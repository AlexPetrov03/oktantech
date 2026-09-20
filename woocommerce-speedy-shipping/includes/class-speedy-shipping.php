<?php

class Speedy_Shipping extends WC_Shipping_Method
{
    /**
     * The ID of the shipping method.
     *
     * @var string
     */
    public $id = 'speedy_shipping';

    /**
     * The title of the method.
     *
     * @var string
     */
    public $method_title = 'Доставка със Speedy';

    /**
     * The description of the method.
     *
     * @var string
     */
    public $method_description = 'Доставка със Speedy';

    /**
     * The supported features.
     *
     * @var array
     */
    public $supports = [
        'settings',
    ];

    /**
     * Initialize a new shipping method instance.
     *
     * @return void
     */
    public function __construct() {
        $this->init_form_fields();
        $this->init_settings();
        $this->registerHooks();

        $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'no';
        $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : 'Доставка с Speedy';

        add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'maybe_hide_shipping_price' ), 10, 2 );
    }

    private static function is_truthy_flag( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return ( (float) $value ) !== 0.0;
        }

        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'y', 'allowed' ], true );
        }

        return ! empty( $value );
    }

    private static function get_destination_service_id( array $service ) {
        if ( isset( $service['serviceId'] ) ) {
            return (int) $service['serviceId'];
        }

        if ( isset( $service['id'] ) ) {
            return (int) $service['id'];
        }

        return 0;
    }

    private static function get_destination_cod_policies( $destination_response ) {
        $policies = [];

        if (
            ! is_array( $destination_response )
            || empty( $destination_response['services'] )
            || ! is_array( $destination_response['services'] )
        ) {
            return $policies;
        }

        foreach ( $destination_response['services'] as $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }

            $service_id = self::get_destination_service_id( $service );
            if ( $service_id <= 0 ) {
                continue;
            }

            $policy = [
                'serviceId' => $service_id,
                'allowed'   => false,
                'forbidden' => false,
                'message'   => '',
            ];

            $cod = $service['additionalServices']['cod'] ?? null;
            if ( ! is_array( $cod ) ) {
                $policies[ $service_id ] = $policy;
                continue;
            }

            if ( array_key_exists( 'allowance', $cod ) ) {
                $allowance = strtoupper( trim( (string) $cod['allowance'] ) );
                if ( $allowance === 'ALLOWED' ) {
                    $policy['allowed'] = true;
                } elseif ( $allowance === 'FORBIDDEN' ) {
                    $policy['forbidden'] = true;
                }
            }

            if ( array_key_exists( 'allowed', $cod ) ) {
                $policy['allowed'] = self::is_truthy_flag( $cod['allowed'] );
            }

            if ( array_key_exists( 'forbidden', $cod ) ) {
                if ( is_array( $cod['forbidden'] ) ) {
                    $policy['forbidden'] = true;

                    if ( ! empty( $cod['forbidden']['message'] ) ) {
                        $policy['message'] = (string) $cod['forbidden']['message'];
                    } elseif ( ! empty( $cod['forbidden']['error']['message'] ) ) {
                        $policy['message'] = (string) $cod['forbidden']['error']['message'];
                    }
                } else {
                    $policy['forbidden'] = self::is_truthy_flag( $cod['forbidden'] );
                }
            }

            $policies[ $service_id ] = $policy;
        }

        return $policies;
    }

    private static function get_destination_obpd_policies( $destination_response ) {
        $policies = [];

        if (
            ! is_array( $destination_response )
            || empty( $destination_response['services'] )
            || ! is_array( $destination_response['services'] )
        ) {
            return $policies;
        }

        foreach ( $destination_response['services'] as $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }

            $service_id = self::get_destination_service_id( $service );
            if ( $service_id <= 0 ) {
                continue;
            }

            $policy = [
                'serviceId' => $service_id,
                'allowed'   => false,
                'forbidden' => false,
            ];

            $obpd = $service['additionalServices']['obpd'] ?? $service['additionalServices']['obpDetails'] ?? null;
            if ( ! is_array( $obpd ) ) {
                $policies[ $service_id ] = $policy;
                continue;
            }

            if ( array_key_exists( 'allowance', $obpd ) ) {
                $allowance = strtoupper( trim( (string) $obpd['allowance'] ) );
                if ( $allowance === 'ALLOWED' ) {
                    $policy['allowed'] = true;
                } elseif ( $allowance === 'FORBIDDEN' ) {
                    $policy['forbidden'] = true;
                }
            }

            if ( array_key_exists( 'allowed', $obpd ) ) {
                $policy['allowed'] = self::is_truthy_flag( $obpd['allowed'] );
            }

            if ( array_key_exists( 'forbidden', $obpd ) ) {
                $policy['forbidden'] = self::is_truthy_flag( $obpd['forbidden'] );
            }

            $policies[ $service_id ] = $policy;
        }

        return $policies;
    }

    private static function resolve_cod_processing_for_policy( $moneytransfer_mode, $is_bg_destination, array $policy = [] ) {
        $result = [
            'status'         => 'fallback',
            'processingType' => 'CASH',
            'message'        => '',
        ];

        if ( $moneytransfer_mode !== 'YES' ) {
            return $result;
        }

        if ( ! empty( $policy['forbidden'] ) ) {
            $result['status'] = 'forbidden';
            $result['processingType'] = '';
            $result['message'] = ! empty( $policy['message'] ) ? (string) $policy['message'] : 'Наложеният платеж не е позволен за избраната услуга.';
            return $result;
        }

        if ( ! empty( $policy['allowed'] ) ) {
            $result['status'] = $is_bg_destination ? 'postal-money-transfer' : 'cash-fallback';
            $result['processingType'] = $is_bg_destination ? 'POSTAL_MONEY_TRANSFER' : 'CASH';
            return $result;
        }

        if ( $is_bg_destination ) {
            $result['processingType'] = 'POSTAL_MONEY_TRANSFER';
        }

        return $result;
    }

    private static function resolve_cod_processing_for_destination( array $policies, $requested_service_id, $moneytransfer_mode, $is_bg_destination ) {
        if ( $moneytransfer_mode !== 'YES' ) {
            return [
                'serviceId'      => 0,
                'status'         => 'fallback',
                'processingType' => 'CASH',
                'message'        => '',
            ];
        }

        if ( $requested_service_id > 0 && isset( $policies[ $requested_service_id ] ) ) {
            $resolved = self::resolve_cod_processing_for_policy(
                $moneytransfer_mode,
                $is_bg_destination,
                $policies[ $requested_service_id ]
            );

            $resolved['serviceId'] = (int) $requested_service_id;
            return $resolved;
        }

        foreach ( $policies as $service_id => $policy ) {
            if ( ! empty( $policy['allowed'] ) && empty( $policy['forbidden'] ) ) {
                $resolved = self::resolve_cod_processing_for_policy(
                    $moneytransfer_mode,
                    $is_bg_destination,
                    $policy
                );

                $resolved['serviceId'] = (int) $service_id;
                return $resolved;
            }
        }

        foreach ( $policies as $service_id => $policy ) {
            if ( ! empty( $policy['forbidden'] ) ) {
                $resolved = self::resolve_cod_processing_for_policy(
                    $moneytransfer_mode,
                    $is_bg_destination,
                    $policy
                );

                $resolved['serviceId'] = (int) $service_id;
                return $resolved;
            }
        }

        return [
            'serviceId'      => 0,
            'status'         => $is_bg_destination ? 'postal-money-transfer' : 'cash-fallback',
            'processingType' => $is_bg_destination ? 'POSTAL_MONEY_TRANSFER' : 'CASH',
            'message'        => '',
        ];
    }

    private static function normalize_fileprice_delivery_target( $value ) {
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        $normalized = function_exists( 'mb_strtolower' )
            ? mb_strtolower( trim( (string) $value ), 'UTF-8' )
            : strtolower( trim( (string) $value ) );

        $map = [
            'address'    => 0,
            'адрес'      => 0,
            'до адрес'   => 0,
            'office'     => 1,
            'офис'       => 1,
            'до офис'    => 1,
            'office2'    => 2,
            'automat'    => 2,
            'автомат'    => 2,
            'до автомат' => 2,
            'apt'        => 2,
        ];

        return array_key_exists( $normalized, $map ) ? $map[ $normalized ] : -1;
    }

    private static function should_send_obpd_for_destination( array $policies, $requested_service_id ) {
        if ( $requested_service_id > 0 ) {
            if ( ! isset( $policies[ $requested_service_id ] ) ) {
                return false;
            }

            return ! empty( $policies[ $requested_service_id ]['allowed'] ) && empty( $policies[ $requested_service_id ]['forbidden'] );
        }

        if ( empty( $policies ) ) {
            return false;
        }

        foreach ( $policies as $policy ) {
            if ( empty( $policy['allowed'] ) || ! empty( $policy['forbidden'] ) ) {
                return false;
            }
        }

        return true;
    }

    private static function normalize_admin_shipping_type( $shipping_type, $delivery_type = null, $is_foreign_order = false, $foreign_office_id = 0, $office_id = 0 ) {
        if ( $delivery_type !== null ) {
            $delivery_type = (int) $delivery_type;

            if ( $delivery_type === 0 ) {
                return 'office2';
            }

            if ( $delivery_type === 1 ) {
                return 'office';
            }

            if ( $delivery_type === 2 ) {
                return 'address';
            }
        }

        $shipping_type = trim( (string) $shipping_type );

        if ( $shipping_type === 'Офис' ) {
            $shipping_type = 'office';
        }

        if ( ! in_array( $shipping_type, [ 'office', 'office2', 'address' ], true ) ) {
            $shipping_type = 'address';
        }

        if ( $is_foreign_order && $foreign_office_id <= 0 && $office_id <= 0 && $shipping_type !== 'address' ) {
            return 'address';
        }

        return $shipping_type;
    }



    /**
     * Initialize the form fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Статус на модула',
                'type' => 'checkbox',
                'description' => '(включено/изключено)',
                'default' => 'yes',
            ],
            'title' => [
                'title' => 'Заглавие',
                'type' => 'text',
                'default' => 'Доставка с Speedy',
            ],
            'speedy_name' => [
                'title' => 'Потребителско име <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#usernamepassword"><i class="fa fa-question-circle">i</i></a>',
                'type'  => 'text'
            ],
            'speedy_password' => [
                'title' => 'Парола <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#usernamepassword"><i class="fa fa-question-circle">i</i></a>',
                'type'  => 'password'
            ]
        ];

        // Add tabs to the settings
        $this->form_fields['tabs'] = [
            'title' => '',
            'type'  => 'tabs',
            'tabs'  => [
               'general_settings' => [
                    'title' => 'Общи настройки',
                    'content' => 'Версия на модула за доставка: 2.1.4 - In case of technical questions, contact us at woo@speedy.bg',
                ],
                'sending' => [
                    'title' => 'Изпращане',
                   'content' => 'Версия на модула за доставка: 2.1.4 - In case of technical questions, contact us at woo@speedy.bg',
                ],
                'services' => [
                    'title' => 'Услуги',
                    'content' => 'Версия на модула за доставка: 2.1.4 - In case of technical questions, contact us at woo@speedy.bg',
                ],
                'payment' => [
                    'title' => 'Плащане',
                    'content' => 'Версия на модула за доставка: 2.1.4 - In case of technical questions, contact us at woo@speedy.bg',
                ],
            ],
        ];

        if( ! $this->is_logged_in() ) {
            $unauthenticated_fields = [            
                'information_message' => [
                    'type'   => 'message',
                    'message'=> 'Трябва да въведете валидни име и парола и да ги запазите, за да се отключат допълнителните настройки'
                ]
            ];
            
            $this->form_fields = array_merge( $this->form_fields, $unauthenticated_fields );
        } else {
            $office_id = isset( $this->settings['sender_office'] ) ? $this->settings['sender_office'] : 0;
            $office = Speedy_DB::get_office_by_id( $office_id );
            $office_options = [];

            if( $office ) {
                $office_options[$office->id] = $office->address;
            }


// Връща клиентите от API, кеширани за 24 часа
if (!function_exists('speedy_get_clients')) {
    function speedy_get_clients() {
        $cache_key = 'speedy_clients_cache';
        $clients = get_transient($cache_key);

        if ($clients === false) {
            $clients = [];

            // Хардкоднати данни за тест
            $arr_data = [
                'userName' => '999783',
                'password' => '9356141711'
            ];

            // curl заявка към API
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.speedy.bg/v1/client/contract');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            // декодиране на JSON
            $data = json_decode($response, true);

            if (isset($data['clients']) && is_array($data['clients'])) {
                foreach ($data['clients'] as $client) {
                    $clients[$client['clientId']] =
                        'ID: ' . $client['clientId'] .
                        ', ' . ($client['clientName'] ?? '') .
                        ', ' . ($client['objectName'] ?? '') .
                        ', Адрес: ' . ($client['address']['fullAddressString'] ?? '');
                }
            }

            // кеширане 24 часа
            set_transient($cache_key, $clients, DAY_IN_SECONDS);
        }

        return $clients;
    }
}

if (!function_exists('speedy_get_clients_live')) {
    function speedy_get_clients_live() {

        $clients = [];

        // Вземаме динамично username и password
        $arr_data = [
            'userName' => speedy_username(),
            'password' => speedy_password()
        ];

        // cURL заявка към Speedy API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.speedy.bg/v1/client/contract');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("Speedy cURL ERROR: " . $err);
            return [];
        }

        // Декодиране на JSON
        $data = json_decode($response, true);

        // Добавяне на клиенти в масив
        if (isset($data['clients']) && is_array($data['clients'])) {
            foreach ($data['clients'] as $client) {
                $clients[$client['clientId']] =
                    'ID: ' . $client['clientId'] .
                    ', ' . ($client['clientName'] ?? '') .
                    ', ' . ($client['objectName'] ?? '') .
                    ', Адрес: ' . ($client['address']['fullAddressString'] ?? '');
            }
        }

        return $clients;
    }
}




// Връща допълнителни изисквания, кеширани за 24 часа
if (!function_exists('speedy_get_special_requirements')) {
    function speedy_get_special_requirements() {
        $cache_key = 'speedy_special_reqs_cache';
        $requirements_list = get_transient($cache_key);

        if ($requirements_list === false) {
            $requirements_list = ['-- Изберете --'];

            $arr_data = [
                'userName' => speedy_username(),
                'password' => speedy_password()
            ];

            // Извикване на API с curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, SPEEDY_API_BASE_URL . 'client/contract/info');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                error_log('Speedy CURL ERROR (special requirements): ' . $curl_error);
                return $requirements_list;
            }

            $response_data = json_decode($response, true);

            // Проверка и обработка на отговора
            if (is_array($response_data)
                && isset($response_data['specialDeliveryRequirements']['requirements'])
                && is_array($response_data['specialDeliveryRequirements']['requirements'])
            ) {
                foreach ($response_data['specialDeliveryRequirements']['requirements'] as $req) {
                    if (isset($req['text'])) {
                        $requirements_list[] = $req['text'];
                    }
                }
            } else {
                error_log('Speedy API (special requirements) returned empty or invalid response: ' . print_r($response_data, true));
            }

            // Кеширане за 24 часа
            set_transient($cache_key, $requirements_list, DAY_IN_SECONDS);
        }

        return $requirements_list;
    }
}

if (!function_exists('speedy_get_special_requirements_live')) {
    function speedy_get_special_requirements_live() {

        $requirements_list = ['-- Изберете --'];

        // Вземаме динамично username и password
        $arr_data = [
            'userName' => speedy_username(),
            'password' => speedy_password()
        ];

        // cURL заявка към Speedy API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SPEEDY_API_BASE_URL . 'client/contract/info');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('Speedy cURL ERROR (special requirements): ' . $err);
            return $requirements_list;
        }

        // Декодиране на JSON
        $data = json_decode($response, true);

        // Проверка и добавяне на специални изисквания
        if (isset($data['specialDeliveryRequirements']['requirements'])
            && is_array($data['specialDeliveryRequirements']['requirements'])) {

            foreach ($data['specialDeliveryRequirements']['requirements'] as $req) {
                if (isset($req['text'])) {
                    $requirements_list[] = $req['text'];
                }
            }
        }

        return $requirements_list;
    }
}



// Връща услугите, кеширани за 24 часа
if (!function_exists('speedy_get_services')) {
    function speedy_get_services() {
        $cache_key = 'speedy_allservices_cache';
        $services_list = get_transient($cache_key);

        if ($services_list === false) {
            $services_list = [];

            $arr_data = [
                'userName' => speedy_username(),
                'password' => speedy_password(),
            ];

            // Извикване на API с curl
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, SPEEDY_API_BASE_URL . 'services');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                error_log('Speedy CURL ERROR (services): ' . $curl_error);
                return $services_list;
            }

            $response_data = json_decode($response, true);

            // Проверка и обработка на отговора
            if (is_array($response_data) && isset($response_data['services']) && is_array($response_data['services'])) {
                foreach ($response_data['services'] as $service) {
                    if (isset($service['id'], $service['name'])) {
                        $services_list[$service['id']] = $service['id'] . ' - ' . $service['name'];
                    }
                }
            } else {
                error_log('Speedy API (services) returned empty or invalid response: ' . print_r($response_data, true));
            }

            // Кеширане за 24 часа
            set_transient($cache_key, $services_list, DAY_IN_SECONDS);
        }

        return $services_list;
    }
}

if (!function_exists('speedy_get_services_live')) {
    function speedy_get_services_live() {

        $services_list = [];

        // Вземаме динамично username и password
        $arr_data = [
            'userName' => speedy_username(),
            'password' => speedy_password()
        ];

        // cURL заявка към Speedy API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SPEEDY_API_BASE_URL . 'services');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('Speedy CURL ERROR (services): ' . $err);
            return $services_list;
        }

        // Декодиране на JSON
        $data = json_decode($response, true);

        // Проверка и добавяне на услуги
        if (isset($data['services']) && is_array($data['services'])) {
            foreach ($data['services'] as $service) {
                if (isset($service['id'], $service['name'])) {
                    $services_list[$service['id']] = $service['id'] . ' - ' . $service['name'];
                }
            }
        }

        return $services_list;
    }
}




$clientids    = speedy_get_clients_live();
$dopalnitelni = speedy_get_special_requirements_live();
$uslugi       = speedy_get_services_live();

/**
 * Взима офисите от Speedy API и връща асоциативен масив
 * [id => "id име - адрес"]
 */
if ( ! function_exists( 'get_speedy_offices' ) ) {
    function get_speedy_offices() {
        $cache_key = 'speedy_offices_cache';

        $officesarray = get_transient($cache_key);
        if (is_array($officesarray) && !empty($officesarray)) {
            return $officesarray;
        }

        if ($officesarray === []) {
            delete_transient($cache_key);
        }

        $arr_data = [
            'userName'  => speedy_username(),
            'password'  => speedy_password(),
            'countryId' => 100,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SPEEDY_API_BASE_URL . 'location/office');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($arr_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            error_log('Speedy CURL ERROR (offices): ' . $curl_error);
            return [];
        }

        $offices_response = json_decode($response, true);
        $officesarray = [];

        if (
            is_array($offices_response)
            && isset($offices_response['offices'])
            && is_array($offices_response['offices'])
            && !empty($offices_response['offices'])
        ) {
            foreach ($offices_response['offices'] as $office) {
                if (isset($office['id'], $office['name'], $office['address']['fullAddressString'])) {
                    $fullAddress = $office['address']['fullAddressString'];

                    $officesarray[$office['id']] = [
                        'sort_key' => mb_strtoupper($office['name'], 'UTF-8'),
                        'label'    => $office['id'] . ' ' . $office['name'] . ' - ' . $fullAddress,
                    ];
                }
            }

            uasort($officesarray, function($a, $b) {
                return strcmp($a['sort_key'], $b['sort_key']);
            });

            $officesarray = array_map(function($item) {
                return $item['label'];
            }, $officesarray);

            if (!empty($officesarray)) {
                set_transient($cache_key, $officesarray, DAY_IN_SECONDS);
            }
        } else {
            error_log('Speedy API (offices) returned empty or invalid response: ' . print_r($offices_response, true));
        }

        return $officesarray;
    }
}


//$officesarray = get_speedy_offices();



// Проверка за offices
//if (is_array($offices_response) && isset($offices_response['offices'])) {
//    $offices = $offices_response['offices'];

 //   foreach ($offices as $office) {
//        if (isset($office['id'], $office['name'], $office['address']['fullAddressString'])) {
            // Събиране на адреса и името
//            $fullAddress = $office['address']['fullAddressString'];
            // Добавяне на информация в масива (име и адрес)
//            $officesarray[$office['id']] = $office['name'] . ' - ' . $fullAddress;
//        }
//    }
//}

            
            $authenticated_fields = [
                 'printer'   => [
                    'title'       => 'Принтер за етикети <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#labelPrinter"><i class="fa fa-question-circle">i</i></a>',
                   'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'additionalcopy'   => [
                    'title'       => 'Допълнително хартиено копие на товарителниците <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#additionalWaybillPaperCopy"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'status_update_enabled' => [
                    'title'   => 'Актуализиране на статусите',
                    'type'    => 'select',
                    'default' => 'NO',
                    'options' => [
                        'NO'  => 'Не',
                        'YES' => 'Да',
                    ],
                ],
                'status_update_mappings' => [
                    'title' => 'Синхронизация на статусите <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#sync"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'status_mapping',
                ],
                'availability_countries_mode' => [
                    'title'   => 'Модулът е активен при доставка до <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#nalichnost"><i class="fa fa-question-circle">i</i></a>',
                    'type'    => 'select',
                    'default' => 'all',
                    'options' => [
                        'all'      => 'Всички налични държави',
                        'specific' => 'Избрани държави',
                    ],
                ],
                'availability_specific_countries' => [
                    'title'       => 'Държави',
                    'type'        => 'country_multiselect',
                    'description' => 'Изберете държавите, за които методът за доставка Speedy да бъде наличен.',
                ],
                'sender_id'     => [
                    'title' => 'Подател на пратката (адрес/обект) <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#pickUpOffice"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'select',
                    'options' => $clientids
                ],
                'sender_name'     => [
                    'title' => 'Лице за контакти <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#contactName"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'text'
                ],
                'sender_email'    => [
                    'title' => 'Имейл <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#contactName"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'email',
                    'custom_class' => 'emailpole'
                ],
                'sender_phone'    => [
                    'title' => 'Телефон <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#contactName"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'text'
                ],
                'sender_time'    => [
                    'title' => 'Край на работното време <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#visitEndTime"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'text'
                ],
                'sender_city'     => [
                    'title' => 'Град <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#pickUpOffice"><i class="fa fa-question-circle">i</i></a>',
                    'type'  => 'text'
                ],
                'sender_officeyesno'    => [
                    'title'  => 'Изпращане от офис <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#sendFromSpeedyOffice"><i class="fa fa-question-circle">i</i></a>',
                    'type'   => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'sender_office'    => [
                    'title'  => 'Избор на офис <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#sendFromSpeedyOffice"><i class="fa fa-question-circle">i</i></a>',
                    'type'   => 'select',
                    'options' => get_speedy_offices()
                ],
                'regenerate_data' => [
                    'title'       => 'Извличане на данни от Спиди <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#datafromspeedy"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'data_button'
                ],
                'opakovka'   => [
                    'title'       => 'Опаковка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#package"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'text',
                    'default'     => 'BOX'
                ],
                 'addressonefield'   => [
                    'title'       => 'Адрес на получателя само в едно поле <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#addressNoteField"><i class="fa fa-question-circle">i</i></a>',
                   'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'saturdayoption'   => [
                    'title'       => 'Доставка в събота или празник <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#saturdayDelivery"><i class="fa fa-question-circle">i</i></a>',
                   'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'fast_checkout'   => [
                    'title'       => 'Включване на бърза поръчка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#quickorder"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'checkbox',
                    'default'     => 'no'
                ],
                'uslugi' => [
                    'title'   => 'Активни услуги <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#courierServices"><i class="fa fa-question-circle">i</i></a>',
                    'type'    => 'select',
                    'options' => $uslugi,
                    'multiple' => true,
                    'default'  => ['505'],
                ],
                'uslugitext' => [
                    'title'   => 'Активни услуги <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#courierServices"><i class="fa fa-question-circle">i</i></a>',
                    'type'    => 'hidden',
                ],
                'teglo' => [
                    'title'       => 'Тегло по подразбиране за пратка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#weight"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'number',
                    'default'     => '1'
                ],
                'obqvena' => [
                    'title'       => 'Обявена стойност <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#declaredValue"><i class="fa fa-question-circle">i</i></a>',
                  'type'    => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА',
                    ],
                ],
                'chuplivost' => [
                    'title'       => 'Чупливо <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#declaredValue"><i class="fa fa-question-circle">i</i></a>',
                  'type'    => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА',
                    ],
                ],
               /* 'generate_waybill' => [
                    'title'       => 'Автоматични товарителници <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#auto"><i class="fa fa-question-circle">i</i></a>',
                    'description' => 'Автоматично създаване на товарителница при завършване на поръчка в check out страницата',
                    'type'        => 'checkbox',
                    'default'     => 'no'
                ], */
                'test_before_pay' => [
                    'title'       => 'Опции преди плащане/получаване <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#obpd"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'checkbox',
                    'type'    => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'OPEN'    => 'Отвори',
                        'TEST' => 'Тествай',
                    ],
                ],
                'testplatec' => [
                    'title'       => 'Платец на куриерска услуга на товарителница за връщане <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#obpd"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'checkbox',
                    'type'    => 'select',
                    'options' => [
                        'SENDER'    => 'Подател',
                        'RECIPIENT'    => 'Получател',
                    ],
                ],
                'autoclose' => [
                    'title'       => 'Автоматично изключване на опциите преди плащане при доставка до автомат <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#obpd"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'checkbox',
                    'type'    => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'vaucher' => [
                    'title'       => 'Ваучер за връщане <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#returnVoucher"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'select',
                    'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                 'vaucherpayer' => [
                    'title'   => 'Платец на куриерска услуга по товарителница за връщане <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#returnVoucher"><i class="fa fa-question-circle">i</i></a>',
                    'type'    => 'select',
                    'custom_class' => 'vaucherpayerpole',
                    'options' => [
                        'SENDER'    => 'Подател',
                        'RECIPIENT' => 'Получател',
                    ],
                ],
                'vaucherpayerdays' => [
                    'title'   => 'Период на валидност в дни <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#returnVoucher"><i class="fa fa-question-circle">i</i></a>',
                     'type'        => 'number',
                    'custom_class' => 'vaucherpayerdayspole'
                ],
                'dopalnitelni' => [
                    'title'       => 'Допълнителни изисквания при обслужване <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#specialDeliveryId"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'select',
                     'options' => $dopalnitelni
                    
                ],
                'nachinplashtane' => [
                    'title'       => 'Начин на изплащане на наложен платеж <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#methodTransfer"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'text',
                    'default'     => 'по договор',
                    'disabled'    => true
                ],
                'includeshippingprice' => [
                    'title'       => 'Включване на цената за доставката в стойността на НП <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#includeShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                   'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'moneytransfer' => [
                    'title'       => 'Видове Наложен платеж <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#moneyTransfer"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'select',
                     'options' => [
                        'NO'    => 'Наложен платеж',
                        'YES'    => 'Наложен платеж чрез Пощенски паричен превод',
                         'fiscal'    => 'Касов бон за Наложен платеж по артикули',
                         'fiscalone'    => 'Касов бон за Наложен платеж по данъчни групи'
                    ],
                ],
                'cenadostavka' => [
                    'title'       => 'Образуване на цена за доставка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'checkbox',
                    'type'    => 'select',
                    'options' => [
                        'speedycalculator'    => 'Спиди калкулатор',
                        'fixedprices'    => 'Фиксирана цена за доставка',
                        'freeshipping' => 'Безплатна доставка',
                        'fileprices' => 'Собствени цени',
                         'nadbavka' => 'Спиди калкулатор + надбавка за обработка',
                    ],
                ],
                 'suma_nadbavka' => [
                    'title'       => 'Сума на надбавката <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'suma-nadbavka'
                ],
                 'fileceni' => [
                    'title'       => 'Качи CSV file с цени <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'file',
                    'custom_class'=> 'fileceni'
                ],
                'free_shipping' => [
                    'title'       => 'Безплатна доставка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'description' => 'Сума НАД посочената тук, активира безплатна доставка до офис/адрес. Пояснение: Ако желаете потребителите да получават безплатна доставка при достигане на Х сума - въведете я с 0.01 по-малко в съответното поле. Например, за безплатна доставка при достигане на 100лв - въведете 99.99 и т.н.',
                    'type'        => 'checkbox',
                    'custom_class'=> 'free-shipping-izbor',
                    'default'     => 'no'
                ],
                'free_shipping_automat' => [
                    'title'       => 'Безплатна доставка до АВТОМАТ над сума <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'free-shipping-automat',
                    'hidden_by'   => 'free_shipping'
                ],
                'free_shipping_office' => [
                    'title'       => 'Безплатна доставка до ОФИС над сума <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'free-shipping-office',
                    'hidden_by'   => 'free_shipping'
                ],
                'free_shipping_address' => [
                    'title'       => 'Безплатна доставка до АДРЕС над сума <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'free-shipping-address',
                    'hidden_by'   => 'free_shipping'
                ],
                'fixed_shipping' => [
                    'title'       => 'Фиксирана цена на доставка <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'description' => 'Включване на фиксирана цена за доставка до офис/адрес',
                    'type'        => 'checkbox',
                     'custom_class'=> 'fixedpricingpole',
                    'default'     => 'no'
                ],
                'fixed_shipping_automat' => [
                    'title'       => 'Цена на доставка до АВТОМАТ <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'fixed-shipping-automat',
                    'hidden_by'   => 'fixed_shipping'
                ],
                'fixed_shipping_office' => [
                    'title'       => 'Цена на доставка до ОФИС <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'fixed-shipping-office',
                    'hidden_by'   => 'fixed_shipping'
                ],
                'fixed_shipping_address' => [
                    'title'       => 'Цена на доставка до АДРЕС <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#calculateShippingPrice"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'hideable',
                    'custom_type' => 'number',
                    'custom_class'=> 'fixed-shipping-address',
                    'hidden_by'   => 'fixed_shipping'
                ],
                'administrative' => [
                    'title'       => 'Административна такса <a target="_blank" href="https://services.speedy.bg/api/speedy_modules_settings.html#administrativeFee"><i class="fa fa-question-circle">i</i></a>',
                    'type'        => 'select',
                     'options' => [
                        'NO'    => 'НЕ',
                        'YES'    => 'ДА'
                    ],
                ],
                'currency_rate' => [
                'title' => 'Валутни курсове за COD',
                'type' => 'currency_rates',
                'description' => '1 EUR = X (по ISO код)',
                ]
            ];

            $this->form_fields = array_merge( $this->form_fields, $authenticated_fields );
        }
    }

    /**
     * Calculate the shipping fees.
     *
     * @param  array  $package
     * @return void
     */
    public function calculate_shipping($package = []) {
        global $woocommerce;

        $post_data = isset( $_POST['post_data'] ) ? $_POST['post_data'] : '';
        parse_str( $post_data, $data );

        if ( empty( $data['payment_method'] ) ) {
            if ( ! empty( $_REQUEST['payment_method'] ) ) {
                $data['payment_method'] = sanitize_text_field( wp_unslash( $_REQUEST['payment_method'] ) );
            } elseif ( ! empty( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
                parse_str( wp_unslash( $_REQUEST['post_data'] ), $parsed_post );
                if ( ! empty( $parsed_post['payment_method'] ) ) {
                    $data['payment_method'] = sanitize_text_field( $parsed_post['payment_method'] );
                }
            }
        }

        if ( empty( $data['billing_country'] ) && ! empty( $data['shipping_country'] ) ) {
            $data['billing_country'] = $data['shipping_country'];
        }

        if ( empty( $data['billing_city'] ) && ! empty( $data['shipping_city'] ) ) {
            $data['billing_city'] = $data['shipping_city'];
        }

        if ( empty( $data['city_id'] ) && ! empty( $data['shipping_city'] ) ) {
            $shipping_city_raw = is_string( $data['shipping_city'] ) ? trim( $data['shipping_city'] ) : $data['shipping_city'];
            if ( is_numeric( $shipping_city_raw ) ) {
                $data['city_id'] = $shipping_city_raw;
            }
        }

        
if ( empty($data['speedy_selected_service_id']) ) {
    if ( isset($_POST['speedy_selected_service_id']) ) {
        $data['speedy_selected_service_id'] = (int) sanitize_text_field( wp_unslash($_POST['speedy_selected_service_id']) );
    } elseif ( isset($_REQUEST['speedy_selected_service_id']) ) {
        $data['speedy_selected_service_id'] = (int) sanitize_text_field( wp_unslash($_REQUEST['speedy_selected_service_id']) );
    } elseif ( !empty($_REQUEST['post_data']) && is_string($_REQUEST['post_data']) ) {
        parse_str( wp_unslash($_REQUEST['post_data']), $parsed_post_selected );
        if ( !empty($parsed_post_selected['speedy_selected_service_id']) ) {
            $data['speedy_selected_service_id'] = (int) $parsed_post_selected['speedy_selected_service_id'];
        }
    }
}


        $cost = ( !empty( $data ) ) ? Speedy_API::calculate_shipping( $data ) : WC()->session->get( 'shipping_speedy_shipping_cost' );

        $this->add_rate([
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => $cost
        ]);
    }

    public function is_logged_in() {
        return $this->get_option( 'logged_in', false );
    }

    public function is_price_fixed() {
        return $this->get_option( 'fixed_shipping', 'no' ) === 'yes';
    }

    public function get_price_office() {
        return $this->get_option( 'fixed_shipping_office', 0 );
    }

    public function get_price_automat() {
        return $this->get_option( 'fixed_shipping_automat', 0 );
    }

    public function get_price_address() {
        return $this->get_option( 'fixed_shipping_address', 0 );
    }

    public function get_free_shipping_over_amount() {
        $free_shipping_amount = $this->get_option( 'free_shipping', 0 );

        if(is_numeric($free_shipping_amount) && $free_shipping_amount > 0) {
            return $free_shipping_amount;
        } else {
            return FALSE;
        }
    }

    /**
     * Filter Hooks
     */
    public function maybe_hide_shipping_price( $label, $method ) {
        global $woocommerce;
        
        $meta_data = $method->get_meta_data();

        if( isset( $meta_data['missing_address'] ) )
            // return $method->get_label() . ': Моля въведете адрес';

        if( is_cart() && $method->get_id() === $this->id )
            return $method->get_label();
        
        if( $method->get_id() === $this->id ) {
            $cost = $method->get_cost();
            $free_shipping_amount = $this->get_free_shipping_over_amount();
            if( $cost == 0 && $free_shipping_amount != FALSE && $woocommerce->cart->cart_contents_total <= $free_shipping_amount) 
                return $method->get_label() . ': Моля въведете адрес';

            $method_prefix = '';
            if($free_shipping_amount) {
                if($woocommerce->cart->cart_contents_total > $free_shipping_amount) {
                    $method_prefix = 'БЕЗПЛАТНА ';
                }
            }

            return $method_prefix . $method->get_label() . ': ' . wc_price( $cost );
        }
        
        return $label;
    }
    
    /**
     * Custom fields
     */

public function generate_currency_rates_html( $key, $values ) {
    $saved = isset( $this->settings['currency_rate'] ) && is_array( $this->settings['currency_rate'] )
        ? $this->settings['currency_rate']
        : [];

 


$saved = isset( $this->settings['currency_rate'] ) && is_array( $this->settings['currency_rate'] )
    ? $this->settings['currency_rate']
    : [];

ob_start();
?>
<tr valign="top">
    <th scope="row" class="titledesc">
        <label for="woocommerce_<?php echo esc_attr( $this->id . '_' . $key ); ?>">
            <?php echo esc_html( $values['title'] ); ?>
        </label>
    </th>
    <td class="forminp">
        <input type="hidden" id="woocommerce_speedy_shipping_currency_rate" value="1" />
        <table class="widefat" id="speedy-currency-rates-table" style="max-width:700px;">
     
<thead>
    <tr>
        <th style="padding-left: 14px;">Код на валута (ISO)</th>
        <th style="padding-left: 10px;">Курс (1 EUR = X)</th>
        <th></th>
    </tr>
</thead>
            <tbody>
           <?php $i = 0; foreach ( $saved as $item ) : ?>
<tr id="currency_row_<?php echo (int) $i; ?>">
    <td>
        <input type="text"
               class="woocommerce_speedy_shipping_method_currency_rate currency-iso"
               name="woocommerce_speedy_shipping_method_currency_rate[<?php echo (int) $i; ?>][iso_code]"
               placeholder="ISO Code"
               value="<?php echo esc_attr( strtoupper( (string) $item['iso_code'] ) ); ?>"
               maxlength="3" />
    </td>
    <td>
        <input type="text"
               class="woocommerce_speedy_shipping_method_currency_rate currency-rate"
               name="woocommerce_speedy_shipping_method_currency_rate[<?php echo (int) $i; ?>][rate]"
               placeholder="Rate"
               value="<?php echo esc_attr( (string) $item['rate'] ); ?>" />
    </td>
    <td>
        <button type="button" class="button remove_currency">Изтриване</button>
    </td>
</tr>
<?php $i++; endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:10px;">
            <button type="button" class="button" id="speedy-add-currency-rate">Добавяне на валута / Валутен курс</button>
        </p>

        <?php if ( ! empty( $values['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $values['description'] ); ?></p>
        <?php endif; ?>
    </td>
</tr>
<?php
return ob_get_clean();
    }
    public function generate_hideable_html( $key, $values ) {
        // Текущо записана стойност на полето
        $value = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';

        // По подразбиране: полето се показва
        $is_shown = 'yes';

        // Ако е зададен 'hidden_by', гледаме настройката на това поле
        if ( ! empty( $values['hidden_by'] ) ) {
            $hidden_by_key = $values['hidden_by'];

            $is_shown = isset( $this->settings[ $hidden_by_key ] )
                    ? $this->settings[ $hidden_by_key ]
                    : 'no';
        }

        $style = ( 'no' === $is_shown ) ? 'display: none' : 'display: table-row';

        ob_start();
        ?>
        <tr valign="top"
            class="<?php echo esc_attr( $values['custom_class'] ); ?>"
            style="<?php echo esc_attr( $style ); ?>">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $key ); ?>">
                   <?php echo wp_kses( $values['title'], [
    'a' => [ 'href' => [], 'target' => [], 'class' => [] ],
    'i' => [ 'class' => [] ],
] ); ?>
                </label>
            </th>

            <td class="forminp forminp-<?php echo esc_attr( sanitize_title( $values['type'] ) ); ?>">
                <fieldset>
                    <legend class="screen-reader-text">
                       <?php echo wp_kses( $values['title'], [
    'a' => [ 'href' => [], 'target' => [], 'class' => [] ],
    'i' => [ 'class' => [] ],
] ); ?>
                    </legend>
                    <input
                            type="<?php echo esc_attr( $values['custom_type'] ); ?>"
                            class="input-text regular-input"
                            value="<?php echo esc_attr( $value ); ?>"
                            id="<?php echo esc_attr( $key ); ?>"
                            name="woocommerce_<?php echo esc_attr( $this->id . '_' . $key ); ?>" />
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    public function generate_data_button_html( $key, $values ) {
        ob_start();
        ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?= esc_attr( $key ) ?>"><?= $values['title'] ?></label>
                </th>

                <td class="forminp forminp-<?= sanitize_title( $values['type'] ) ?>">
                    <fieldset>
                        <button type="button" class="regenerate-data-speedy button-secondary">
                            Извличане на данни
                        </button>

                        <?php if ( ! empty( $values['description'] ) ) : ?>
                            <p class="description"><?= esc_html( $values['description'] ); ?></p>
                        <?php endif; ?>

                    </fieldset>
                </td>
            </tr>
        <?php

        return ob_get_clean();
    }

    public function generate_status_mapping_html( $key, $values ) {
        $saved_mappings = isset( $this->settings['status_update_mappings'] ) && is_array( $this->settings['status_update_mappings'] )
            ? $this->settings['status_update_mappings']
            : [];
        $status_options = [ '' => 'Изберете статус' ] + wc_get_order_statuses();
        $speedy_statuses = speedy_get_final_tracking_statuses();
        $enabled = isset( $this->settings['status_update_enabled'] ) ? $this->settings['status_update_enabled'] : 'NO';
        $style = ( 'YES' === $enabled ) ? 'display: table-row;' : 'display: none;';

        ob_start();
        ?>
        <tr valign="top" style="<?php echo esc_attr( $style ); ?>">
            <th scope="row" class="titledesc">
                <label for="woocommerce_<?php echo esc_attr( $this->id . '_' . $key ); ?>">
                    <?php echo wp_kses( $values['title'], [
                        'a' => [ 'href' => [], 'target' => [], 'class' => [] ],
                        'i' => [ 'class' => [] ],
                    ] ); ?>
                </label>
            </th>
            <td class="forminp">
                <input type="hidden" id="woocommerce_<?php echo esc_attr( $this->id . '_' . $key ); ?>" value="1" />
                <table class="widefat striped" style="max-width: 860px;">
                    <thead>
                        <tr>
                            <th style="padding-left: 3px;">Спиди окончателен статус</th>
                            <th>WooCommerce статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $speedy_statuses as $code => $status ) : ?>
                            <tr>
                                <td><?php echo esc_html( speedy_get_final_tracking_status_label( $code ) ); ?></td>
                                <td>
                                    <select name="woocommerce_<?php echo esc_attr( $this->id ); ?>_status_update_mappings[<?php echo esc_attr( $code ); ?>]" style="min-width: 260px;">
                                        <?php foreach ( $status_options as $status_key => $status_label ) : ?>
                                            <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $saved_mappings[ $code ] ?? '', $status_key ); ?>>
                                                <?php echo esc_html( $status_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    public function validate_status_mapping_field( $key, $value ) {
        $posted_value = isset( $_POST[ 'woocommerce_' . $this->id . '_' . $key ] )
            ? wp_unslash( $_POST[ 'woocommerce_' . $this->id . '_' . $key ] )
            : [];
        $allowed_statuses = wc_get_order_statuses();
        $clean_mappings = [];

        if ( ! is_array( $posted_value ) ) {
            return $clean_mappings;
        }

        foreach ( speedy_get_final_tracking_statuses() as $code => $status_data ) {
            $selected_status = isset( $posted_value[ $code ] ) ? sanitize_text_field( (string) $posted_value[ $code ] ) : '';

            if ( $selected_status !== '' && isset( $allowed_statuses[ $selected_status ] ) ) {
                $clean_mappings[ $code ] = $selected_status;
            }
        }

        return $clean_mappings;
    }

    public function generate_country_multiselect_html( $key, $values ) {
        $saved_countries = isset( $this->settings[ $key ] ) && is_array( $this->settings[ $key ] )
            ? $this->settings[ $key ]
            : [];
        $countries = function_exists( 'WC' ) && WC()->countries
            ? WC()->countries->get_countries()
            : [];
        $field_id = 'woocommerce_' . $this->id . '_' . $key;

        ob_start();
        ?>
        <tr valign="top" id="<?php echo esc_attr( $field_id ); ?>_row">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $values['title'] ); ?></label>
            </th>
            <td class="forminp">
                <select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>[]" multiple="multiple" style="min-width: 320px; min-height: 180px;">
                    <?php foreach ( $countries as $country_code => $country_name ) : ?>
                        <option value="<?php echo esc_attr( $country_code ); ?>" <?php selected( in_array( $country_code, $saved_countries, true ) ); ?>>
                            <?php echo esc_html( $country_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( ! empty( $values['description'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $values['description'] ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    public function validate_country_multiselect_field( $key, $value ) {
        $posted_value = isset( $_POST[ 'woocommerce_' . $this->id . '_' . $key ] )
            ? (array) wp_unslash( $_POST[ 'woocommerce_' . $this->id . '_' . $key ] )
            : [];
        $countries = function_exists( 'WC' ) && WC()->countries
            ? WC()->countries->get_countries()
            : [];
        $selected_countries = [];

        foreach ( $posted_value as $country_code ) {
            $country_code = strtoupper( sanitize_text_field( (string) $country_code ) );

            if ( isset( $countries[ $country_code ] ) ) {
                $selected_countries[] = $country_code;
            }
        }

        return array_values( array_unique( $selected_countries ) );
    }

     /**
     * Render the tabs for settings.
     *
     * @return void
     */
    public function generate_tabs_html( $key, $value ) {
        $tabs = $value['tabs'];
        $file_path = get_option('speedy_fileceni_path');
$csv_data = [];

if ($file_path && file_exists($file_path)) {
    if (($handle = fopen($file_path, "r")) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            $csv_data[] = $row;
        }
        fclose($handle);
    }
}
        ob_start(); ?>
        <div class="speedy-tabs">
            <ul class="tabs">
                <?php foreach ( $tabs as $tab_key => $tab ) : ?>
                    <li><a href="#<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( $tab['title'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php foreach ( $tabs as $tab_key => $tab ) : ?>
                <div id="<?php echo esc_attr( $tab_key ); ?>" class="tab-content">
                    <p><?php echo esc_html( $tab['content'] ); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <style>
            .forminp label a{
                display: none;
            }
            .speedy-tabs ul.tabs {
                display: flex;
                list-style: none;
                padding: 0;
                margin: 0;
                position: relative;
                top: 7px;
            }
            .speedy-tabs .active{
                    border: 2px solid #999!important;
                    color: #000!important;
            }
            .speedy-tabs ul.tabs li {
                margin-right: 10px;
            }
            .speedy-tabs ul.tabs li a {
                text-decoration: none;
                padding: 5px 10px;
                display: inline-block;
                border: 1px solid #ccc;
                border-bottom: none;
                background: #f9f9f9;
            }
            .speedy-tabs ul.tabs li a:hover {
                background: #eee;
            }
            .speedy-tabs .tab-content {
                display: none;
                border: 1px solid #ccc;
                padding: 10px;
                margin-top: -1px;
            }
            .speedy-tabs .tab-content.active {
                display: block;
            }

            tr:has(#woocommerce_speedy_shipping_fixed_shipping) {
                display: none !important;
            }
            tr:has(#woocommerce_speedy_shipping_free_shipping) {
                display: none !important;
            }




        </style>
        <script>

            document.addEventListener('DOMContentLoaded', function () {
                 const sumaRow = document.querySelector("tr.suma-nadbavka");
                if (sumaRow && sumaRow.nextElementSibling) {
                    sumaRow.nextElementSibling.classList.add("fileceni");
                }

                const tabs = document.querySelectorAll('.speedy-tabs ul.tabs li a');
                const contents = document.querySelectorAll('.speedy-tabs .tab-content');

                tabs.forEach(tab => {
                    tab.addEventListener('click', function (e) {
                        e.preventDefault();

                        tabs.forEach(t => t.classList.remove('active'));
                        contents.forEach(c => c.classList.remove('active'));

                        this.classList.add('active');
                        const target = document.querySelector(this.getAttribute('href'));
                        if (target) {
                            target.classList.add('active');
                        }
                    });
                });

                // Set the first tab active by default
                if (tabs.length > 0) {
                    tabs[0].classList.add('active');
                    contents[0].classList.add('active');
                }
            });

            //tabs making content
            document.addEventListener('DOMContentLoaded', function() {
    // Get all table rows
    const tableRows = document.querySelectorAll('.form-table > tbody > tr');

    document.getElementById("woocommerce_speedy_shipping_nachinplashtane").readOnly = true;
    
    // Function to show/hide rows and enable/disable inputs based on selected tab
    function updateTabContent(activeTab) {
        // First, hide all rows
        tableRows.forEach(row => {
            row.style.display = 'none';
        });

        const tabFieldMapping = {
                'general_settings': [
                    'woocommerce_speedy_shipping_enabled',
                    'woocommerce_speedy_shipping_title',
                    'woocommerce_speedy_shipping_speedy_name',
                    'woocommerce_speedy_shipping_speedy_password',
                    'woocommerce_speedy_shipping_printer',
                    'woocommerce_speedy_shipping_additionalcopy',
                    'woocommerce_speedy_shipping_status_update_enabled',
                    'woocommerce_speedy_shipping_status_update_mappings',
                    'woocommerce_speedy_shipping_availability_countries_mode',
                    'woocommerce_speedy_shipping_availability_specific_countries'
                ],
                'sending': [
                    'woocommerce_speedy_shipping_sender_id',
                    'woocommerce_speedy_shipping_sender_name',
                    'woocommerce_speedy_shipping_sender_phone',
                    'woocommerce_speedy_shipping_sender_time',
                    //'woocommerce_speedy_shipping_sender_city',
                    'woocommerce_speedy_shipping_sender_officeyesno',
                    'woocommerce_speedy_shipping_sender_office',
                    'woocommerce_speedy_shipping_regenerate_data',
                    'woocommerce_speedy_shipping_addressonefield',
                    'woocommerce_speedy_shipping_saturdayoption',
                    'woocommerce_speedy_shipping_opakovka',
                    //'woocommerce_speedy_shipping_fast_checkout',
                    'regenerate_data'
                ],
                'services': [
                    'woocommerce_speedy_shipping_uslugi',
                    'woocommerce_speedy_shipping_teglo',
                    'woocommerce_speedy_shipping_obqvena',
                    'woocommerce_speedy_shipping_chuplivost',
                    'woocommerce_speedy_shipping_vaucher',
                    'woocommerce_speedy_shipping_vaucherpayer',
                    'woocommerce_speedy_shipping_vaucherpayerdays',
                    'woocommerce_speedy_shipping_generate_waybill',
                    'woocommerce_speedy_shipping_test_before_pay',
                     'woocommerce_speedy_shipping_testplatec',
                      'woocommerce_speedy_shipping_autoclose',
                    'woocommerce_speedy_shipping_dopalnitelni'
                ],
                'payment': [
                    'woocommerce_speedy_shipping_nachinplashtane',
                    'woocommerce_speedy_shipping_includeshippingprice',
                    'woocommerce_speedy_shipping_moneytransfer',
                    'woocommerce_speedy_shipping_cenadostavka',
                    'woocommerce_speedy_shipping_free_shipping',
                    'suma_nadbavka',
                    'fileceni',
                    'free_shipping_automat',
                    'free_shipping_office',
                    'free_shipping_address',
                    'woocommerce_speedy_shipping_fixed_shipping',
                    'fixed_shipping_automat',
                    'fixed_shipping_office',
                    'fixed_shipping_address',
                    'woocommerce_speedy_shipping_administrative',
                    'woocommerce_speedy_shipping_currency_rate',
                ]
            };

        // Show and enable rows with matching inputs
        const activeFields = tabFieldMapping[activeTab] || [];
        activeFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                const row = field.closest('tr');
                if (row) {
                    row.style.display = 'table-row';

                    // Enable inputs in this row
                    const inputs = row.querySelectorAll('input, select, button');
                    inputs.forEach(input => {
                        input.disabled = false;
                    });
                }
                const rows = document.querySelectorAll('.form-table > tbody > tr');

                if (rows.length >= 13) {
                    const thirteenthRow = rows[12];
                }
            }
        });

        if (activeTab === 'general_settings') {
            updateAvailabilityCountriesField();
        }
    }

    function updateAvailabilityCountriesField() {
        const availabilityMode = document.getElementById('woocommerce_speedy_shipping_availability_countries_mode');
        const countriesField = document.getElementById('woocommerce_speedy_shipping_availability_specific_countries');

        if (!availabilityMode || !countriesField) {
            return;
        }

        const countriesRow = countriesField.closest('tr');
        if (countriesRow) {
            countriesRow.style.display = availabilityMode.value === 'specific' ? 'table-row' : 'none';
        }
    }

    // Add event listeners to tabs
    const tabs = document.querySelectorAll('.speedy-tabs .tabs a');
    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();

            // Remove active class from all tabs and contents
            document.querySelectorAll('.speedy-tabs .tabs a').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.speedy-tabs .tab-content').forEach(c => c.classList.remove('active'));

            // Add active class to clicked tab and its content
            this.classList.add('active');
            const targetTab = this.getAttribute('href');
            document.querySelector(targetTab).classList.add('active');

            // Update table rows and input states
            updateTabContent(this.getAttribute('href').replace('#', ''));
        });
    });

    // Initial setup - show only first two rows in general settings
    updateTabContent('general_settings');

    const availabilityMode = document.getElementById('woocommerce_speedy_shipping_availability_countries_mode');
    if (availabilityMode) {
        availabilityMode.addEventListener('change', updateAvailabilityCountriesField);
    }
});

document.addEventListener("DOMContentLoaded", function() {
    function toggleThirteenthRow() {
        // Select all rows in the table
        const rows = document.querySelectorAll('.form-table > tbody > tr');

        // Check if the 13th row exists
        if (rows.length >= 13) {
            const thirteenthRow = rows[12]; // Index 12 (0-based)
            const activeTab = document.querySelector('.tabs li a[href="#sending"]');

            if (activeTab && activeTab.classList.contains('active')) {
                thirteenthRow.style.setProperty('display', 'contents', 'important');
            } else {
                thirteenthRow.style.setProperty('display', 'none', 'important');
            }
        } else {
            console.error("The 13th <tr> does not exist. Total rows found: ", rows.length);
        }
    }

    // Run the function on page load
    toggleThirteenthRow();

    // Listen for clicks on the tabs to check when the "sending" tab becomes active
    document.querySelectorAll('.tabs li a').forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove 'active' class from all tabs and set it only on the clicked one
            document.querySelectorAll('.tabs li a').forEach(link => link.classList.remove('active'));
            this.classList.add('active');

            // Run the function again to update row visibility
            toggleThirteenthRow();
        });
    });
});



document.addEventListener('DOMContentLoaded', function () {
    function setupToggleVisibility(checkboxId) {
        const checkbox = document.getElementById(checkboxId);
        if (checkbox) {
            const targetRow = checkbox.closest('tr').nextElementSibling;
            if (targetRow) {
                const updateVisibility = () => {
                    targetRow.style.display = checkbox.checked ? 'table-row' : 'none';
                };
                checkbox.addEventListener('change', updateVisibility);
                updateVisibility();
                const observer = new MutationObserver(() => updateVisibility());
                observer.observe(targetRow, { attributes: true, attributeFilter: ['style'] });
            } else {
                console.error(`Second <tr> element not found for ${checkboxId}.`);
            }
        } else {
            console.error(`Checkbox with id "${checkboxId}" not found.`);
        }
    }

    //setupToggleVisibility('woocommerce_speedy_shipping_chuplivost');
});


document.addEventListener("DOMContentLoaded", function() {
    let inputField = document.getElementById("woocommerce_speedy_shipping_nachinplashtane");
    if (inputField) {
        inputField.setAttribute("value", "по договор");
    }
});

document.addEventListener("DOMContentLoaded", function() {
    let select = document.getElementById("woocommerce_speedy_shipping_cenadostavka");

    // Ensure the first option is selected if nothing is selected
    if (!select.value) {
        select.value = "speedycalculator";
    }

    function updateVisibility() {
        let selectedValue = select.value;

        let sumanadbavka = document.querySelectorAll(".suma-nadbavka");
        let fileceni = document.querySelectorAll(".fileceni");
        let freeShippingOffice = document.querySelectorAll(".free-shipping-office");
         let freeShippingOffice2 = document.querySelectorAll(".free-shipping-automat");
        let freeShippingAddress = document.querySelectorAll(".free-shipping-address");
        let fixedShippingOffice = document.querySelectorAll(".fixed-shipping-office");
        let fixedShippingAutomat = document.querySelectorAll(".fixed-shipping-automat");
        let fixedShippingAddress = document.querySelectorAll(".fixed-shipping-address");
        let fixedShippingAddress2 = document.querySelectorAll("tr:has(+ .fixed-shipping-office");
        let fixedShippingAddress3 = document.querySelectorAll("tr:has(+ .fixed-shipping-automat");
        let freeShippingAddress2 = document.querySelectorAll("tr:has(+ .free-shipping-office)");
        let freeShippingAddress3 = document.querySelectorAll("tr:has(+ .free-shipping-automat)");

        // Hide all elements by default
        let allElements = [...sumanadbavka,...freeShippingOffice, ...freeShippingOffice2, ...freeShippingAddress, ...fixedShippingOffice, ...fixedShippingAutomat, ...fixedShippingAddress, ...fixedShippingAddress2, ...freeShippingAddress2, ...freeShippingAddress3];
        allElements.forEach(el => {
           el.style.visibility = "hidden";   // Hides the element without affecting layout
            el.style.position = "absolute";   // Removes the element from layout
            el.style.opacity = "0";           // Makes it fully transparent
            el.style.width = "0";             // Collapse the element's width
            el.style.height = "0";            // Collapse the element's height
            el.style.padding = "0";           // Removes any padding
            el.style.border = "none";         // Removes border if any
            el.style.transition = "opacity 0.3s ease, height 0.3s ease, width 0.3s ease"; // Smooth transition
       
        });

        // Show elements based on selection
        function showElements(elements) {
            elements.forEach(el => {
               el.style.visibility = "visible";
        el.style.position = "relative";  // или "static"
        el.style.opacity = "1";
        el.style.width = "auto";
        el.style.height = "auto";
        el.style.padding = "";
        el.style.border = "";
             });
        }

        if (selectedValue === "fileprices") {
            //showElements(fileceni);

        }

        if (selectedValue === "fixedprices") {
             showElements(fixedShippingAutomat);
            showElements(fixedShippingOffice);
            showElements(fixedShippingAddress);
            showElements(fixedShippingAddress2);
             showElements(fixedShippingAddress3);
        } else if (selectedValue === "freeshipping") {
            showElements(freeShippingOffice);
            showElements(freeShippingOffice2);
            showElements(freeShippingAddress);
            showElements(freeShippingAddress2);
            showElements(freeShippingAddress3);
        } else if (selectedValue === "fileprices") {
            //showElements(fileceni);
        } else if (selectedValue === "nadbavka") {
            showElements(sumanadbavka);
        }
    }

    // Run on page load
    updateVisibility();

    // Listen for changes
    select.addEventListener("change", updateVisibility);
});

// Select field for vauchers
document.addEventListener("DOMContentLoaded", function () {
    let select = document.getElementById("woocommerce_speedy_shipping_vaucher");

    function toggleNextRows() {
        let currentRow = select.closest("tr"); // Find the <tr> containing the select
        let nextRow = currentRow.nextElementSibling; // Get the first next <tr>
        let secondNextRow = nextRow ? nextRow.nextElementSibling : null; // Get the second next <tr>

        // Function to toggle visibility
        function toggleRow(row, show) {
            if (row) {
                if (show) {
                    row.style.visibility = "visible";
                    row.style.position = "";
                    row.style.opacity = "1";
                    row.style.height = "";
                } else {
                    row.style.visibility = "hidden";
                    row.style.position = "absolute";
                    row.style.opacity = "0";
                    row.style.height = "0";
                }
            }
        }

        const shouldShow = select.value === "YES";

        toggleRow(nextRow, shouldShow);
        toggleRow(secondNextRow, shouldShow);
    }

    // Run on page load
    toggleNextRows();

    // Listen for changes in the select field
    select.addEventListener("change", toggleNextRows);
});


document.addEventListener('DOMContentLoaded', function () {
  const selectElement = document.getElementById('woocommerce_speedy_shipping_cenadostavka');
  const fixedCheckbox = document.getElementById('woocommerce_speedy_shipping_fixed_shipping');
  const freeCheckbox = document.getElementById('woocommerce_speedy_shipping_free_shipping');
  const fixedShippingRow = document.querySelector('tr.fixed-shipping-office');
  const fixedShippingRow2 = document.querySelector('tr.fixed-shipping-automat');
  const freeShippingRow = document.querySelector('tr.free-shipping-office');
  const freeShippingRow2 = document.querySelector('tr.free-shipping-automat');


  function hidePreviousRow(row) {
    if (row && row.previousElementSibling) {
      const rowToHide = row.previousElementSibling;
      rowToHide.style.setProperty('display', 'none', 'important');
      rowToHide.style.setProperty('visibility', 'hidden', 'important');
      rowToHide.style.setProperty('opacity', '0', 'important');
      rowToHide.style.setProperty('height', '0px', 'important');
      rowToHide.style.setProperty('width', '0px', 'important');
      rowToHide.style.setProperty('padding', '0px', 'important');
      rowToHide.style.setProperty('margin', '0px', 'important');
      rowToHide.style.setProperty('transition', 'none', 'important');
      const cells = rowToHide.querySelectorAll('td, th');
      cells.forEach(cell => {
        cell.style.setProperty('padding', '0px', 'important');
        cell.style.setProperty('margin', '0px', 'important');
        cell.style.setProperty('border', '0px', 'important');
        cell.style.setProperty('height', '0px', 'important');
        cell.style.setProperty('line-height', '0px', 'important');
        cell.style.setProperty('font-size', '0px', 'important');
      });
    }
  }

  // Handle changes to the shipping options
  selectElement.addEventListener('change', function () {
    if (this.value === 'fixedprices') {
      if (fixedCheckbox) {
        fixedCheckbox.checked = true;
        fixedCheckbox.setAttribute('value', '1');
      }
      if (freeCheckbox) {
        freeCheckbox.checked = false;
        freeCheckbox.setAttribute('value', '0');
      }
       hidePreviousRow(fixedShippingRow2);
       hidePreviousRow(freeShippingRow2);
    } else if (this.value === 'freeshipping') {
      if (freeCheckbox) {
        freeCheckbox.checked = true;
        freeCheckbox.setAttribute('value', '1');
      }
      if (fixedCheckbox) {
        fixedCheckbox.checked = false;
        fixedCheckbox.setAttribute('value', '0');
      }
      
       hidePreviousRow(freeShippingRow2);
      hidePreviousRow(fixedShippingRow2);
    } else if (this.value === 'speedycalculator') {
      // Use setTimeout to ensure changes take effect after other scripts are executed
      setTimeout(() => {
        if (freeCheckbox) {
          freeCheckbox.checked = false;
          freeCheckbox.setAttribute('value', '0');
        }
        if (fixedCheckbox) {
          fixedCheckbox.checked = false;
          fixedCheckbox.setAttribute('value', '0');
        }

        // Hide the previous rows for both shipping options
        hidePreviousRow(fixedShippingRow);
         hidePreviousRow(fixedShippingRow2);
        hidePreviousRow(freeShippingRow);
        hidePreviousRow(freeShippingRow2);
      }, 100); // Wait 100ms to make sure all updates are applied
    }
  });
});



//скриване за избор на офис ДА НЕ
document.addEventListener("DOMContentLoaded", function () {
  const select = document.getElementById("woocommerce_speedy_shipping_sender_officeyesno");
  const currentTr = select.closest('tr');
  const row = currentTr.nextElementSibling;

  function toggleRow() {
    const show = select.value === "YES";
    if (show) {
      row.style.visibility = "visible";
      row.style.position = "";
      row.style.opacity = "1";
      row.style.height = "";
    } else {
      row.style.visibility = "hidden";
      row.style.position = "absolute";
      row.style.opacity = "0";
      row.style.height = "0";
    }
  }

  select.addEventListener("change", toggleRow);
  toggleRow(); // извикваме веднъж при зареждане
});


//скриване на автоматично спиране за автомати опциите за плащане
jQuery(document.body).ready(function($) {
    function toggleNextRows() {
        var select = $('#woocommerce_speedy_shipping_test_before_pay');
        var value = select.val();

        var currentRow = select.closest('tr');
        var nextTwoRows = currentRow.nextAll('tr').slice(0, 2);

        // Проверка за активен таб "Услуги"
        var servicesTabActive = $('ul.tabs li a[href="#services"]').hasClass('active');

        // Покажи редовете само ако сме на таб "Услуги" ИЛИ селектът е OPEN/TEST и табът е активен
        if (servicesTabActive && (value === 'OPEN' || value === 'TEST')) {
            nextTwoRows.each(function() {
                $(this).css({
                    'display': '',
                    'visibility': '',
                    'height': ''
                });
            });
        } else {
            nextTwoRows.each(function() {
                $(this).css({
                    'display': 'none',
                    'visibility': 'hidden',
                    'height': '0'
                });
            });
        }
    }

    // Първоначална проверка при зареждане
    toggleNextRows();

    // При промяна на селекта
    $('#woocommerce_speedy_shipping_test_before_pay').change(function() {
        toggleNextRows();
    });

    // При смяна на таб
    $('ul.tabs li a').click(function() {
        setTimeout(toggleNextRows, 50); // кратко забавяне за обновяване на class="active"
    });
});




// file upload success Функция за показване на popup
function showSuccessPopup(message, timeout = 3000) {
    // Създаваме контейнер
    const popup = document.createElement("div");
    popup.id = "speedy-success-popup";
    popup.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #4caf50;
        color: #fff;
        padding: 20px 30px;
        font-size: 18px;
        font-weight: bold;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-width: 300px;
    `;

    // Текст
    const span = document.createElement("span");
    span.innerText = message;
    popup.appendChild(span);

    // Бутон за затваряне
    const closeBtn = document.createElement("button");
    closeBtn.innerHTML = "&times;";
    closeBtn.style.cssText = `
        background: transparent;
        border: none;
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        margin-left: 15px;
    `;
    closeBtn.onclick = () => popup.remove();
    popup.appendChild(closeBtn);

    // Добавяме popup-а към body
    document.body.appendChild(popup);

    // Автоматично затваряне
    setTimeout(() => popup.remove(), timeout);
}

// Пример: показва popup ако в URL има ?custom_redirect=yes
document.addEventListener("DOMContentLoaded", () => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("custom_redirect") === "yes") {
        showSuccessPopup("✅ Файлът със собствени цени е успешно приложен.", 3000);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('woocommerce_speedy_shipping_status_update_enabled');
    const mappingsAnchor = document.getElementById('woocommerce_speedy_shipping_status_update_mappings');

    if (!toggle || !mappingsAnchor) {
        return;
    }

    const mappingsRow = mappingsAnchor.closest('tr');
    if (!mappingsRow) {
        return;
    }

    const syncMappingsVisibility = function() {
        const activeTab = document.querySelector('.speedy-tabs .tabs a.active');
        const isGeneralTabActive = activeTab && activeTab.getAttribute('href') === '#general_settings';

        mappingsRow.style.display = toggle.value === 'YES' && isGeneralTabActive ? 'table-row' : 'none';
    };

    toggle.addEventListener('change', syncMappingsVisibility);
    document.querySelectorAll('.speedy-tabs .tabs a').forEach(function(tabLink) {
        tabLink.addEventListener('click', function() {
            setTimeout(syncMappingsVisibility, 0);
        });
    });
    syncMappingsVisibility();
});


//fileprices update
jQuery(function($){
    function toggleFileceniRow() {
        var value = $("#woocommerce_speedy_shipping_cenadostavka").val();
        var isPaymentTabActive = $("#payment").hasClass("active"); 

        if (value === "fileprices" && isPaymentTabActive) {
            $("tr.fileceni").css("display", "table-row");
        } else {
            $("tr.fileceni").css("display", "none");
        }
    }

    // Проверка при зареждане
    toggleFileceniRow();

    // Проверка при промяна на селекта
    $("#woocommerce_speedy_shipping_cenadostavka").on("change", toggleFileceniRow);

    // Проверка при смяна на табовете (когато кликнеш на линк в менюто)
    $(".speedy-tabs .tabs a").on("click", function(){
        // Малко забавяне, защото активният клас се сменя от друга логика
        setTimeout(toggleFileceniRow, 50);
    });
});



//adding view of the uploaded csv for prices
document.addEventListener("DOMContentLoaded", function() {
    const data = <?php echo json_encode($csv_data, JSON_UNESCAPED_UNICODE); ?>;
    if (!data.length) return;

    // Заменяме заглавията
    const headers = ["Услуга", "Доставка до", "Тегло", "Сума на поръчка до", "Цена без ДДС"];
    data[0] = headers;

    // Създаваме контейнер
    const previewContainer = document.createElement("div");
    previewContainer.id = "csv-preview";
    previewContainer.style.cssText = "margin-top:15px; max-height:300px; overflow:auto; border:1px solid #ccc; padding:5px;";

    // Заглавие "Собствени цени"
    const title = document.createElement("h3");
    title.innerText = "Собствени цени";
    title.style.margin = "5px 0";
    previewContainer.appendChild(title);

    const table = document.createElement("table");
    table.style.cssText = "border-collapse: collapse; width:100%; font-size:13px;";

    let html = "";
    data.forEach((row, i) => {
        html += "<tr>";
        row.forEach((col, colIndex) => {
            let value = col;

            // Ако сме в колоната "Доставка до" и НЕ е хедър реда
            if (colIndex === 1 && i > 0) {
                if (value == "0") value = "адрес";
                else if (value == "1") value = "офис";
                else if (value == "2") value = "автомат";
            }

            if (i === 0) {
                html += "<th style='border:1px solid #ccc; padding:3px; background:#f9f9f9;'>" + value + "</th>";
            } else {
                html += "<td style='border:1px solid #ccc; padding:3px;'>" + value + "</td>";
            }
        });
        html += "</tr>";
    });

    table.innerHTML = html;
    previewContainer.appendChild(table);

    // Слагаме контейнера след input-а за CSV
    const input = document.getElementById("woocommerce_speedy_shipping_fileceni");
    if (input) {
        input.insertAdjacentElement("afterend", previewContainer);
    }
});


//скриване на чупливост
document.addEventListener('DOMContentLoaded', function () {
    const obqvena = document.getElementById('woocommerce_speedy_shipping_obqvena');
    const chuplivostRow = document.getElementById('woocommerce_speedy_shipping_chuplivost').closest('tr');

    function toggle() {
        if (obqvena.value === 'YES') {
            chuplivostRow.style.visibility = 'visible';
            chuplivostRow.style.height = ''; // връщаме нормалната височина
        } else {
            chuplivostRow.style.visibility = 'collapse'; // скрива реда визуално
            chuplivostRow.style.height = '0'; // редът не заема място
        }
    }

    toggle(); // изпълнява се веднага при зареждане
    obqvena.addEventListener('change', toggle);
});

//regenerate
document.addEventListener('DOMContentLoaded', function () {
    const button = document.querySelector('button.regenerate-data-speedy.button-secondary');
    if (button) {
        const tr = button.closest('tr');
        if (tr) tr.style.display = 'table-row';
    }
});

//aktivni uslugi
jQuery(document).ready(function($) {
    var $select = $('#woocommerce_speedy_shipping_uslugi');
    var $hidden = $('#woocommerce_speedy_shipping_uslugitext');

    $select.hide();

    var $container = $('<div id="speedy-checkboxes" style="margin-top:10px;"></div>');

    // Генерираме чекбоксове
    $select.find('option').each(function(index) {
        var val = $(this).val();
        var text = $(this).text();
        var checked = '';
        if ($hidden.val().split(',').includes(val)) checked = 'checked';

        var hiddenClass = index >= 4 ? 'hidden-service' : '';

        var checkbox = `
            <label class="speedy-option-label ${hiddenClass}" style="display:block; margin-bottom:3px;">
                <input type="checkbox" class="speedy-option" value="${val}" ${checked}> ${text}
            </label>
        `;
        $container.append(checkbox);
    });

    // Добавяме бутоните
    var $buttons = $(`
        <div style="margin-top:10px;">
            <button type="button" id="speedy-select-all" class="button-secondary" style="margin-right:5px;">Избор на всички</button>
            <button type="button" id="speedy-deselect-all" class="button-secondary" style="margin-right:5px;">Премахване избор на всички</button>
            <button type="button" id="speedy-toggle-more" class="button-secondary">Показване на повече</button>
        </div>
    `);

    $container.append($buttons);
    $select.after($container);

    // Скриваме елементите след 4-тия
    $('.hidden-service').hide();

    // При промяна на чекбоксите
    $(document).on('change', '.speedy-option', function() {
        var selected = [];
        $('.speedy-option:checked').each(function() {
            selected.push($(this).val());
        });
        $hidden.val(selected.join(','));
    });

    // Маркирай всички
    $('#speedy-select-all').on('click', function() {
        $('.speedy-option').prop('checked', true).trigger('change');
    });

    // Демаркирай всички
    $('#speedy-deselect-all').on('click', function() {
        $('.speedy-option').prop('checked', false).trigger('change');
    });

    // Покажи повече / по-малко
    $('#speedy-toggle-more').on('click', function() {
        if ($('.hidden-service:visible').length) {
            // Скриваме отново
            $('.hidden-service').hide();
            $(this).text('Показване на повече');
        } else {
            // Показваме всички
            $('.hidden-service').css('display', 'block');
            $(this).text('Показване по-малко');
        }
    });

    // При зареждане – ако има вече избрани стойности, маркираме
    var initialValues = $hidden.val();
    if (initialValues) {
        var arr = initialValues.split(',');
        arr.forEach(function(v) {
            $('.speedy-option[value="'+v+'"]').prop('checked', true);
        });
    }
});

//mailto link correct
document.addEventListener('DOMContentLoaded', function() {
    const email = 'woo@speedy.bg';
    document.querySelectorAll('p').forEach(p => {
        if (p.innerHTML.includes(email)) {
            p.innerHTML = p.innerHTML.replace(
                email,
                `<a href="mailto:${email}">${email}</a>`
            );
        }
    });
});


        </script>
        <?php
        return ob_get_clean();
    }


   


    function generate_speedy_tovaritelnica( $order ){
        if( !$order->has_shipping_method('speedy_shipping') ) return;
         $waybill = json_decode($order->get_meta('shipping_speedy_waybill', true), true);
        $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
        $order_number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order_id;
        $currency = $order->get_currency();
        $billing_address_1  = $order->get_billing_address_1();
        $billing_shipping_type  = $order->get_meta('_billing_shipping_type');
        //$waybill = json_decode($order->get_meta('shipping_speedy_waybill', true), true);
      // Получаваме weight_value с приоритет:
// 1) $_GET['weight'] (ако е подадено)
// 2) тегло от поръчката (сума от product->get_weight() * qty)
// 3) WC()->cart->get_cart_contents_weight() (ако има cart)
// 4) fallback speedy_get_setting('teglo')

$weight_value = 0.0;

// 1) ако е подадено през GET
if ( isset($_GET['weight']) && $_GET['weight'] !== '' ) {
    // приемаме и "1,5" формати
    $weight_value = (float) str_replace(',', '.', $_GET['weight']);
} else {
    // 2) опитваме да сметнем теглото от $order (ако имаш $order в контекста)
    $order_weight = 0.0;
    if ( isset($order) && is_object($order) && method_exists($order, 'get_items') ) {
        foreach ( $order->get_items() as $item ) {
            // опитваме да вземем product обект
            $qty = 1;
            if ( method_exists($item, 'get_quantity') ) {
                $qty = (int) $item->get_quantity();
            } elseif ( isset($item['qty']) ) {
                $qty = (int) $item['qty'];
            }

            $product = null;
            if ( method_exists($item, 'get_product') ) {
                $product = $item->get_product();
            } elseif ( isset($item['product_id']) ) {
                $product = wc_get_product( $item['product_id'] );
            }

            if ( $product ) {
                // опитваме product->get_weight()
                $p_weight = $product->get_weight();
                if ( $p_weight === '' || $p_weight === null ) {
                    // ако не е зададено, опитваме meta
                    $product_id = $product->get_id();
                    $meta_weight = get_post_meta( $product_id, '_weight', true );
                    $p_weight = ($meta_weight !== '' && $meta_weight !== null) ? $meta_weight : 0;
                }
                $order_weight += (float) $p_weight * $qty;
            } else {
                // ако няма product обект, опитваме да прочетем meta от item
                $meta_w = '';
                if ( is_callable([$item, 'get_meta']) ) {
                    $meta_w = $item->get_meta('_weight', true);
                } elseif ( isset($item['_weight']) ) {
                    $meta_w = $item['_weight'];
                }
                $order_weight += (float) $meta_w * $qty;
            }
        }
    }

    // 3) ако не успеем с $order, опитваме WC()->cart
    if ( $order_weight <= 0 && function_exists('WC') && WC()->cart ) {
        $order_weight = floatval( WC()->cart->get_cart_contents_weight() );
    }

    // присвояваме, ако имаме сметнато тегло
    if ( $order_weight > 0 ) {
        $weight_value = (float) $order_weight;
    } else {
        // 4) fallback: настройка
        $weight_value = (float) speedy_get_setting('teglo');
    }
}

// Винаги имай float
$weight_value = (float) $weight_value;

if (isset($_GET['tov']) && $_GET['tov'] == 1 && isset($_GET['error'])) {
    $error_message = 'Грешка в някое от данните на клиента.';
    if (isset($_GET['errmsg'])) {
        $error_message = sanitize_text_field(urldecode($_GET['errmsg']));
    }

    echo '<div id="speedy-success-popup" style="
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: red;
        color: #fff;
        padding: 20px 30px;
        font-size: 13px;
        font-weight: bold;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-width: 300px;
    ">
       <span>Грешка при създаване на товарителница: ' . esc_html($error_message) . '</span>
        <button id="speedy-success-close" style="
            background: transparent;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            margin-left: 15px;
        ">&times;</button>
    </div>';

    echo '<script>
    jQuery(function($){
        $("#speedy-success-close").on("click", function(){
            $("#speedy-success-popup").fadeOut();
        });
        setTimeout(function(){
            $("#speedy-success-popup").fadeOut();
        }, 20000);
    });
    </script>';
}


    if ( $waybill != NULL && isset($_GET['success']) && $_GET['success'] == 1 ) {
       $waybill_json = $order->get_meta('shipping_speedy_waybill', true);
$waybill      = json_decode($waybill_json, true);
 $waybill_number = $waybill['id'];
    echo '<div id="speedy-success-popup" style="
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #4caf50;
        color: #fff;
        padding: 20px 30px;
        font-size: 18px;
        font-weight: bold;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-width: 300px;
    ">
       <span>✅ Успешно създадохте товарителница. Номер: ' . esc_html($waybill_number) . '</span>
        <button id="speedy-success-close" style="
            background: transparent;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            margin-left: 15px;
        ">&times;</button>
    </div>';

    echo '<script>
    jQuery(function($){
        // Затваряне с бутон X
        $("#speedy-success-close").on("click", function(){
            $("#speedy-success-popup").fadeOut();
        });
        // Автоматично затваряне след 3 секунди
        setTimeout(function(){
            $("#speedy-success-popup").fadeOut();
        }, 20000);
    });
    </script>';
}


        if($waybill == NULL) {

       echo '<div id="speedy-generate-section" style="margin: 20px 0; padding: 15px; background: #f8f8f8; border: 1px solid #ccc;">
        <h2 style="margin-top: 0;text-align:left;">Генериране на товарителница Speedy</h2>
            <table class="speedy_generate">
        <input id="taking_date" type="hidden" name="taking_date" value="" />
        <input id="order_id" type="hidden" name="order_id" value="' . $order_id . '" />
        <input id="is_bol_recalculated" type="hidden" name="is_bol_recalculated" value="0" />
        <input id="recalculate" type="hidden" name="recalculate" value="0" />

        <tr>
            <td><label for="contents" class="speedy_required">Съдържание:</label></td>
            <td>
                <input type="text" id="contents" name="contents" value="Поръчка: ' . esc_attr( $order_number ) . '" />
                <br />
                <span style="color: red; display:none;" id="error_contents">Съдържанието трябва да е между 1 и 100 символа!</span>
            </td>
        </tr>
        <tr>
    <td><label for="weight" class="speedy_required">Тегло (кг):</label></td>
    <td>
        <input type="text" id="weight" name="weight" value="' . $weight_value . '" />
        <br />
        <span style="color: red; display:none;" id="error_weight">Моля, попълнете тегло!</span>
    </td>
</tr>
         <tr>
            <td><label for="opakovka" class="speedy_required">Опаковка:</label></td>
            <td>
                <input type="text" id="opakovka" name="opakovka" value="' . speedy_get_setting( 'opakovka' ) . '" />
                <br />
               
            </td>
        </tr>
         <tr>
            <td><label for="obekt" class="speedy_required">Обект от който тръгват пратките:</label></td>
            <td width="200">';
               
          echo '<select name="obekt" id="obekt">';

            $clientids = [];

            $arr_data = ['userName' => speedy_username(), 'password' => speedy_password()];
            $clientid_response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'client/contract', $arr_data);

            if (is_array($clientid_response) && isset($clientid_response['clients'])) {
                foreach ($clientid_response['clients'] as $client) {
                    if (isset($client['clientId'])) {
                        $clientids[$client['clientId']] = 'ID: ' . $client['clientId'] . ', Име: ' . $client['clientName'] . ', ' . $client['objectName'];
                    }
                }
            }

           foreach ($clientids as $id => $label) {
             $selected_id = speedy_get_setting('sender_id');
                $selected = ($id == $selected_id) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($id) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
            }

            echo '</select>';

            
            $ordersubtotal        = wc_format_decimal($order->get_subtotal(), 2);
            $free_shipping_total  = speedy_get_order_products_total_with_vat( $order );
            $free_shipping        = speedy_get_setting('free_shipping');
            $fixedshipping        = speedy_get_setting('fixed_shipping');
            $free_shipping_office = floatval(speedy_get_setting('free_shipping_office'));
            $free_shipping_automat = floatval(speedy_get_setting('free_shipping_automat'));
             $free_shipping_address = floatval(speedy_get_setting('free_shipping_address'));

            // По подразбиране получател плаща
            $selected_sender   = '';
            $selected_receiver = 'selected="selected"';
            $disabledornot = '';
            if ( speedy_get_setting( 'includeshippingprice' ) === 'YES' ) {
    $selected_sender   = 'selected="selected"';
    $selected_receiver = '';
    $disabledornot     = 'disabled="disabled"';
}

            if ($free_shipping == 'yes') {
                $is_free = false;

                if ($billing_shipping_type === 'office' && $free_shipping_total >= $free_shipping_office) {
                    $is_free = true;
                } elseif ($billing_shipping_type === 'office2' && $free_shipping_total >= $free_shipping_automat) {
                    $is_free = true;
                } elseif ($billing_shipping_type === 'address' && $free_shipping_total >= $free_shipping_address) {
                    $is_free = true;
                }

                if ($is_free) {
                    $selected_sender   = 'selected="selected"';
                    $selected_receiver = '';
                     $disabledornot   = 'disabled="disabled"';
                }
            }

            if ($fixedshipping == 'yes') {
                $is_free = false;

               

                
                    $selected_sender   = 'selected="selected"';
                    $selected_receiver = '';
                     $disabledornot   = 'disabled="disabled"';
                
            }

            if(speedy_get_setting('cenadostavka') == 'fileprices'){
                  $selected_sender   = 'selected="selected"';
                    $selected_receiver = '';
                    $disabledornot   = 'disabled="disabled"';
            }

            $billing_country_iso2 = strtoupper((string) $order->get_meta('_billing_country'));
$speedy_country_id = (int) $order->get_meta('_shipping_shipping_country');

$is_foreign_order = ($billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG')
|| ($speedy_country_id > 0 && $speedy_country_id !== 100);

$billing_office_id = (int) $order->get_meta('_billing_office');
$abroad_office_id = (int) $order->get_meta('_billing_abroadoffice_id');
$requested_delivery_type = isset($_GET['doofisaddress']) ? (int) $_GET['doofisaddress'] : null;

$billing_shipping_type = self::normalize_admin_shipping_type(
    $billing_shipping_type,
    $requested_delivery_type,
    $is_foreign_order,
    $abroad_office_id,
    $billing_office_id
);

if ($is_foreign_order) {
$selected_sender = 'selected="selected"';
$selected_receiver = '';
$disabledornot = 'disabled="disabled"';
}

            if(speedy_get_setting('test_before_pay') == 'TEST'){
                  $opciitest  = 'selected="selected"';
                    $opciipregled = '';
                    $opciibez = '';
            }
            else if(speedy_get_setting('test_before_pay') == 'OPEN'){
                 $opciitest  = '';
                    $opciipregled = 'selected="selected"';
                    $opciibez = '';
            }
            else{
                 $opciitest  = '';
                    $opciipregled = '';
                    $opciibez = 'selected="selected"';
            }

            $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
$is_cod = ($payment_method === 'cod');

$cod_checked = $is_cod ? 'checked="checked"' : '';
$no_cod_checked = !$is_cod ? 'checked="checked"' : '';

$default_width  = '';
$default_length = '';
$default_height = '';
$default_pweight = '';
$max_volume = 0.0;

$pick_largest_product = static function( $product ) use ( &$default_width, &$default_length, &$default_height, &$default_pweight, &$max_volume ) {
    if ( ! $product || ! is_object( $product ) ) {
        return;
    }

    $w = (float) $product->get_width();
    $l = (float) $product->get_length();
    $h = (float) $product->get_height();

    if ( $w <= 0 || $l <= 0 || $h <= 0 ) {
        return;
    }

    $volume = $w * $l * $h;
    if ( $volume > $max_volume ) {
        $max_volume = $volume;
        $default_width  = (string) $w;
        $default_length = (string) $l;
        $default_height = (string) $h;

        $pw = (float) $product->get_weight();
        $default_pweight = $pw > 0 ? (string) $pw : (string) $weight_value;
    }
};

foreach ( $order->get_items( 'line_item' ) as $item ) {
    $product = $item->get_product();
    $pick_largest_product( $product );

    if (
        $product
        && is_object( $product )
        && method_exists( $product, 'is_type' )
        && $product->is_type( 'variation' )
    ) {
        $parent_id = (int) $product->get_parent_id();
        if ( $parent_id > 0 ) {
            $parent = wc_get_product( $parent_id );
            $pick_largest_product( $parent );
        }
    }
}


                echo '<br />
               
            </td>
        </tr>
         <tr>
            <td><label for="obekt" class="speedy_required">Платец на куриерската услуга:</label></td>
            <td>
           <select id="speedy_payer_type" name="payer_type"  ' . $disabledornot . '>
    <option value="1" ' . $selected_receiver . '>Получател</option>
    <option value="0" ' . $selected_sender . '>Подател</option>
</select>
               
            </td>
        </tr>
<tr>
    <td><label for="parcel_count">Брой пакети:</label></td>
    <td>
        <input type="number" id="parcel_count" name="parcel_count" value="1" min="1" />
    </td>
</tr>
<tr>
    <td><label>Размери на пакет (см):</label></td>
    <td>
           <table id="parcel_dimensions_table"
        data-default-width="' . esc_attr($default_width) . '"
        data-default-length="' . esc_attr($default_length) . '"
        data-default-height="' . esc_attr($default_height) . '"
        data-default-weight="' . esc_attr($default_pweight) . '"
        style="border-collapse: collapse;">
        <thead>
            <tr>
            <td scope="col" style="text-align:left; padding:0 6px 6px 0;">Дължина (см)</td>
                <td scope="col" style="text-align:left; padding:0 6px 6px 0;">Ширина (см)</td>
                
                <td scope="col" style="text-align:left; padding:0 6px 6px 0;">Височина (см)</td>
                <td scope="col" style="text-align:left; padding:0 6px 6px 0;">Тегло (кг)</td>
            </tr>
        </thead>
        <tbody>
            <tr>
             <td>
                    <input type="number" step="0.01" min="0" style="width: 103px;" placeholder="Дължина" aria-label="Дължина в сантиметри" name="length[]" value="' . esc_attr($default_length) . '" />
                </td>
                <td>
                    <input type="number" step="0.01" min="0" style="width: 103px;" placeholder="Широчина" aria-label="Широчина в сантиметри" name="width[]" value="' . esc_attr($default_width) . '" />
                </td>
                <td>
                    <input type="number" step="0.01" min="0" style="width: 103px;" placeholder="Височина" aria-label="Височина в сантиметри" name="height[]" value="' . esc_attr($default_height) . '" />
                </td>
                <td>
                    <input type="number" step="0.01" min="0" style="width: 103px;" placeholder="Тегло" aria-label="Тегло в килограми" name="weight[]" value="' . esc_attr($default_pweight) . '" />
                </td>
            </tr>
        </tbody>
    </table>
    </td>
</tr>
       <tr>
    <td><label for="nalojen"> Наложен платеж:</label></td>
    <td>
        <input type="radio" id="speedy_cod_yes" name="cod" value="1" ' . $cod_checked . '>
        <label for="speedy_cod_yes">Да</label>
        <input type="radio" id="speedy_cod_no" name="cod" value="0" ' . $no_cod_checked . '>
        <label for="speedy_cod_no">Не</label>
    </td>
</tr>
        <tr>
            <td><label for="opcii">Опции преди плащане:</label></td>
            <td>
               <select name="option_before_payment" id="speedy_option_before_payment">
                                                <option value="no_option" ' . $opciibez . '>Няма</option>
                                                                <option value="test" ' . $opciitest . '>Тест</option>
                                                                <option value="open" ' . $opciipregled . '>Отвори</option>
                 </select>
            </td>
        </tr>
        <!--<tr>
            <td><label for="obqvena">Добавете oбявена стойност:</label></td>
            <td>
              <select id="insurance" name="insurance" >
                                        <option value="1">Да</option>
                    <option value="0" selected="selected">Не</option>
                                    </select>
            </td>
        </tr>-->
         <tr>
            <td><label for="papercopy">Допълнително хартиено копие на товарителницата:</label></td>
            <td>
               <select id="papercopy" name="papercopy" >
                                        <option value="1">Да</option>
                    <option value="0" selected="selected">Не</option>
                                    </select>
            </td>
        </tr>

        <tr id="to_office">
                <td><label>Доставка до:</label></td>
                <td>
                   
                   <select id="doofisaddress" name="doofisaddress">
    <option value="0" ' . ($billing_shipping_type == 'office2' ? 'selected="selected"' : '') . '>До автомат</option>
    <option value="1" ' . (in_array($billing_shipping_type, ['office', 'Офис'], true) ? 'selected="selected"' : '') . '>До офис</option>
    <option value="2" ' . ($billing_shipping_type == 'address' ? 'selected="selected"' : '') . '>До Адрес</option>
</select>
                </td>
            </tr>


        <tr id="officered">
            <td><label for="office" class="speedy_required">До офис/автомат:</label></td>
          
            <td width="200">';
               
         echo '<select id="drop" name="drop" style="width: 602px;">';

$speedy_country_id    = (int) $order->get_meta('_shipping_shipping_country');
$billing_country_iso2 = strtoupper((string) $order->get_meta('_billing_country'));
$is_foreign_order     = ($speedy_country_id > 0 && $speedy_country_id !== 100)
    || ($billing_country_iso2 !== '' && $billing_country_iso2 !== 'BG');

$abroad_office_label  = trim((string) $order->get_meta('_billing_abroadoffice'));

if ($is_foreign_order && in_array($billing_shipping_type, ['office', 'office2'], true)) {
    // при foreign не показваме BG списък
    $foreign_office_id = $abroad_office_id > 0 ? $abroad_office_id : $billing_office_id;
    $label = $abroad_office_label !== '' ? $abroad_office_label : ('Foreign office #' . $foreign_office_id);

    if ($foreign_office_id > 0) {
        echo '<option value="' . (int) $foreign_office_id . '" selected="selected">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    } else {
        echo '<option value="" selected="selected">Няма избран foreign офис</option>';
    }
} elseif (!$is_foreign_order) {
    $officesarray = get_speedy_offices();
    foreach ($officesarray as $id => $label) {
        $selected = ((int) $id === $billing_office_id) ? ' selected' : '';
        echo '<option value="' . (int) $id . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
} else {
    echo '<option value="" selected="selected"></option>';
}

echo '</select>';


                echo '<br />
               
            </td>
        </tr>
         <tr id="addressred" style="display:none;">
            <td><label for="office" class="speedy_required">До адрес:</label></td>
           <td>';

           $amount_bgn = wc_format_decimal($order->get_total(), 2);

            $address1 = $order->get_billing_address_1();
            $address2 = $order->get_billing_address_2();
            $billing_address_index = $order->get_meta('_billing_address_index');
            $shipping_address_index = $order->get_meta('_shipping_address_index');

            $shipping_neighborhood   = $order->get_meta('_shipping_neighborhood');
            $shipping_street         = $order->get_meta('_shipping_street');
            $shipping_street_number  = $order->get_meta('_shipping_street_number');
            $shipping_block          = $order->get_meta('_shipping_block');
            $shipping_entrance       = $order->get_meta('_shipping_entrance');
            $shipping_floor          = $order->get_meta('_shipping_floor');
            $shipping_apartment      = $order->get_meta('_shipping_apartment');

            if (empty($shipping_street) && isset($_GET['streetId'])) {
                $shipping_street = $_GET['streetId'];
            }
            if (empty($shipping_street_number) && isset($_GET['streetNo'])) {
                $shipping_street_number = $_GET['streetNo'];
            }
            if (empty($shipping_neighborhood) && isset($_GET['complexId'])) {
                $shipping_neighborhood = $_GET['complexId'];
            }
             if (empty($shipping_block) && isset($_GET['blockNo'])) {
                $shipping_block = $_GET['blockNo'];
            }
             if (empty($shipping_entrance) && isset($_GET['entranceNo'])) {
                $shipping_entrance = $_GET['entranceNo'];
            }
             if (empty($shipping_floor) && isset($_GET['floorNo'])) {
                $shipping_floor = $_GET['floorNo'];
            }
             if (empty($shipping_apartment) && isset($_GET['apartmentNo'])) {
                $shipping_apartment = $_GET['apartmentNo'];
            }

            // Инициализация на празни стойности
            $town = $complexId = $streetId = $streetNo = $blockNo = $entranceNo = $floorNo = $apartmentNo = "";

            if (preg_match('/(гр\.|с\.)\s*([^\s\d]+)/u', $billing_address_index, $matches)) {
                $town = trim($matches[2]);
            }
            if (preg_match('/ул\.?\s*([^\d]+)\s*(\d+)?/u', $billing_address_index, $matches)) {
                $streetId = trim($matches[1]);
                $streetNo = isset($matches[2]) ? $matches[2] : "";
            }
            if (preg_match('/ап\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $apartmentNo = $matches[1];
            }
            if (preg_match('/ет\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $floorNo = $matches[1];
            }
            if (preg_match('/(вх\.|вход)\s*([^\s]+)/u', $billing_address_index, $matches)) {
                $entranceNo = $matches[2];
            }
            if (preg_match('/бл\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $blockNo = $matches[1];
            }

            if (speedy_get_setting('addressonefield') == "YES") {
    echo '<div style="background-color:#e6f3ff; border:1px solid #b3d7ff; padding:10px; margin-bottom:10px; border-radius:5px; color:#003366; font-size:13px;">
           Клиентът е въвел адрес само в поле „уточнение“. Въведен по този начин подлежи на допълнителна обработка, което може да доведе до грешки, промяна на срока за доставка и стойността на куриерската услуга.
 За да избегнете подобна ситуация, можете да попълните адреса в отделните полета за квартал, улица, номер и блок.
          </div>';

    echo '  <label>Адрес в забележка:</label><br>
            <textarea type="text" id="addressonefield" name="addressonefield" cols="77" rows="2">'
            . htmlspecialchars($address2) . '</textarea><br>';
}

            $order = wc_get_order( $order_id );

            // Изчисляваме коректните стойности след ваучер/отстъпки
            $products_total_incl_tax = 0.0;
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $products_total_incl_tax += (float) $item->get_total() + (float) $item->get_total_tax();
            }

            $declared_value = $products_total_incl_tax;

            $includes_shipping_in_cod = ( speedy_get_setting('includeshippingprice') === 'YES' );
            $shipping_incl_tax        = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();

            // COD: продукти (incl. VAT) + (по избор) доставка (incl. VAT), според настройката
            $cod_amount = $declared_value;



            $shipping_address_index = $order->get_meta('_billing_address_index');

            $billing_city = get_post_meta($order_id, '_billing_city', true);

            // NEW: държава на фактуриране (ISO2)
            $billing_country = '';
            if (is_object($order) && method_exists($order, 'get_billing_country')) {
                $billing_country = (string) $order->get_billing_country();
            }
            if ($billing_country === '') {
                $billing_country = (string) get_post_meta($order_id, '_billing_country', true);
            }
            $billing_country = strtoupper(trim($billing_country));

            if ($billing_country !== '' && $billing_country !== 'BG') {
                $town = '';

                // FIX: за чужбина взимаме града от _billing_address_index (там е текстовият град),
                // а не от _billing_city (който при вас е siteId / числова стойност).
                $idx = trim((string) $shipping_address_index);

                // ако има "Спиди - До адрес" -> взимаме само текста след него
                $marker = 'Спиди - До адрес';
                $pos = mb_stripos($idx, $marker, 0, 'UTF-8');
                if ($pos !== false) {
                    $after = trim(mb_substr($idx, $pos + mb_strlen($marker, 'UTF-8'), null, 'UTF-8'));
                    if ($after !== '') {
                        $idx = $after;
                    }
                }

                // NEW: в _billing_address_index често има и мейл/телефон след "GR", затова
                // търсим "CITY <A-Z> <COUNTRY>" навсякъде в стринга (не само в края).
                // Пример: "KAVALA A GR administrators@... 0898..."
                $country_re = preg_quote($billing_country, '/');
                if ($idx !== '' && preg_match('/\b(.+?)\s+[A-Z]\s+' . $country_re . '\b/u', $idx, $m)) {
                    $town = trim($m[1]);
                } else {
                    // fallback: ако не намерим "A GR" шаблон, взимаме първата смислена част до country кода (ако го има)
                    if ($idx !== '' && preg_match('/^(.+?)\s+' . $country_re . '\b/u', $idx, $m2)) {
                        $town = trim($m2[1]);
                    } else {
                        $town = trim($idx);
                    }
                }

                // последен fallback ако е празно
                if ($town === '') {
                    $town = trim((string) $billing_city);
                }

                // NEW: махаме пощенски код, ако е залепен отпред/отзад на града (foreign)
                $town = preg_replace('/^\s*\d{4,6}\s+/u', '', (string) $town);   // "65201 KAVALA" -> "KAVALA"
                $town = preg_replace('/\s+\d{4,6}\s*$/u', '', (string) $town);   // "KAVALA 65201" -> "KAVALA"
                $town = trim(preg_replace('/\s{2,}/u', ' ', (string) $town));
            }  else {
                // BG: старата логика + FIX за случаите, когато _billing_city е ID (напр. 2659),
                // а истинският текст е в speedy_cities или в _billing_address_index.
                $town = '';

                $billing_city_str = trim((string) $billing_city);

                // 1) ако е числова стойност -> взимаме име на град от базата
                if ($billing_city_str !== '' && is_numeric($billing_city_str)) {
                    $city_obj = Speedy_DB::get_city_by_id((int) $billing_city_str);
                    if ($city_obj && !empty($city_obj->name)) {
                        $town = trim((string) $city_obj->name);
                    }
                }

                // 2) ако е текст "гр."/"с." -> парсваме както досега
                if ($town === '') {
                    if (preg_match('/^(?:с\.|гр\.)\s*(.+)$/u', $billing_city_str, $matches)) {
                        $town = trim($matches[1]);
                    } else {
                        $town = $billing_city_str; // fallback
                    }
                }

                // 3) fallback: ако пак е празно/число, опитваме да извадим града от _billing_address_index
                if ($town === '' || is_numeric($town)) {
                    $idx_bg = trim((string) $shipping_address_index);

                    $marker = 'Спиди - До адрес';
                    $pos = mb_stripos($idx_bg, $marker, 0, 'UTF-8');
                    if ($pos !== false) {
                        $after = trim(mb_substr($idx_bg, $pos + mb_strlen($marker, 'UTF-8'), null, 'UTF-8'));
                        if ($after !== '') {
                            $idx_bg = $after;
                        }
                    }

                    if ($idx_bg !== '' && preg_match('/\b(?:гр\.|с\.)\s*([^\d,]+)/u', $idx_bg, $m3)) {
                        $town = trim($m3[1]);
                    }
                }
            }

         $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '';
$is_cod = ($payment_method === 'cod');

// За INPUT полетата покажи празно ако НЕ е COD, иначе показ действителната стойност
$cod_amount_display = $is_cod ? wc_format_decimal($cod_amount, 2) : '';

// --- Конвертирана сума във валутата на дестинацията ---
$cod_amount_converted = '';
$target_currency = '';
if ($is_foreign_order) {
    // Определи валутата на дестинацията (пример: RO => RON)
    $country_currency_map = [
        'RO' => 'RON',
        // добави и други държави при нужда
    ];
    if (isset($country_currency_map[$billing_country_iso2])) {
        $target_currency = $country_currency_map[$billing_country_iso2];
    }
    if ($target_currency) {
        $currency_rates = speedy_get_setting('currency_rate', []);
        $rate = 0;
        foreach ($currency_rates as $item) {
            if (isset($item['iso_code']) && strtoupper($item['iso_code']) === $target_currency) {
                $rate = (float)$item['rate'];
                break;
            }
        }
        if ($rate > 0) {
            $cod_amount_converted = number_format($cod_amount * $rate, 2);
        }
    }
}

// Определи валутата на дестинацията
$target_currency = '';
$currency_rates = speedy_get_setting('currency_rate', []);
if ($is_foreign_order && $billing_country_iso2 === 'RO') {
    $target_currency = 'RON';
    // намери курса за RON
    $ron_rate = 0;
    foreach ($currency_rates as $item) {
        if (isset($item['iso_code']) && strtoupper($item['iso_code']) === 'RON') {
            $ron_rate = (float)$item['rate'];
            break;
        }
    }
    // Преобразувай сумата
    if ($ron_rate > 0) {
        $cod_amount_converted = round($cod_amount * $ron_rate, 2);
    } else {
        $cod_amount_converted = '';
    }
} else {
    $cod_amount_converted = '';
}
$obqvena_enabled = ( strtoupper((string) speedy_get_setting('obqvena')) === 'YES' );
$declared_value_display = ( $is_cod && $obqvena_enabled ) ? wc_format_decimal($declared_value, 2) : '';

$is_romania_order = ((int) $speedy_country_id === 642) || ($billing_country_iso2 === 'RO');
$address_fields_style = ($is_foreign_order && !$is_romania_order) ? 'style="display:none;"' : '';

$country_iso2_display = $billing_country !== '' ? $billing_country : 'BG';
$country_display_name = $country_iso2_display;

if ( function_exists('WC') && WC()->countries ) {
    $wc_countries = WC()->countries->get_countries();
    if ( isset($wc_countries[$country_iso2_display]) && $wc_countries[$country_iso2_display] !== '' ) {
        $country_display_name = $wc_countries[$country_iso2_display];
    }
}

if ($country_display_name === 'BG') {
    $country_display_name = 'Bulgaria';
}

if ($is_foreign_order && !$is_romania_order) {
    $address_label = 'Адрес 1:';
} else {
    $address_label = 'Улица:';
}

// Вземаме Адрес 2 от order meta (ако е foreign)
$shipping_street2 = '';
if ($is_foreign_order) {
    $shipping_street2 = trim((string) $order->get_meta('_billing_street_2'));
    if (!$shipping_street2) {
        $shipping_street2 = trim((string) $order->get_meta('_billing_street2'));
    }
}

            echo '
<label>Държава:</label>
<input type="text" id="country_display" value="'.esc_attr($country_display_name).'" readonly style="width:300px; background:#f6f7f7;">
<input type="hidden" id="country_iso2" value="'.esc_attr($country_iso2_display).'">
<br>
<label>Град:</label>
<input type="text" id="town" name="town" value="'.htmlspecialchars($town).'" autocomplete="off" style="width:300px;">
<input type="hidden" id="town_id" name="town_id">
   <ul id="town-suggestions" style="border:1px solid #ccc; max-height:200px; overflow:auto; list-style:none; padding:5px; margin-top:2px; width:300px; display:none;"></ul>';
   
   
   if ( $is_foreign_order && $billing_shipping_type === 'address' ) {
echo '
<br>
<label for="note">Пощенски код:</label>
<input type="text" id="note" name="note" value="' . esc_attr($address2) . '" placeholder="Пощенски код" style="width:140px;" inputmode="numeric" />
';
}


echo '



<script>
var parcelCountInput = document.getElementById("parcel_count");
var parcelTable = document.getElementById("parcel_dimensions_table");
var tableBody = parcelTable.getElementsByTagName("tbody")[0];

var defaultWidth  = parcelTable.dataset.defaultWidth || "";
var defaultLength = parcelTable.dataset.defaultLength || "";
var defaultHeight = parcelTable.dataset.defaultHeight || "";
var defaultWeight = parcelTable.dataset.defaultWeight || "";

parcelCountInput.addEventListener("input", function() {
    var count = parseInt(parcelCountInput.value, 10) || 1;
    var currentRows = tableBody.getElementsByTagName("tr").length;

    while (currentRows < count) {
        var row = document.createElement("tr");
        var placeholders = ["Широчина", "Дължина", "Височина", "Тегло"];
        var names = ["width[]", "length[]", "height[]", "weight[]"];

        for (var i = 0; i < placeholders.length; i++) {
            var td = document.createElement("td");
            var input = document.createElement("input");

            input.type = "number";
            input.placeholder = placeholders[i];
            input.name = names[i];
            input.style.width = "103px";

            if (input.name === "width[]")  input.value = defaultWidth;
            if (input.name === "length[]") input.value = defaultLength;
            if (input.name === "height[]") input.value = defaultHeight;
            if (input.name === "weight[]") input.value = defaultWeight;

            td.appendChild(input);
            row.appendChild(td);
        }

        tableBody.appendChild(row);
        currentRows++;
    }

    while (currentRows > count) {
        tableBody.removeChild(tableBody.lastChild);
        currentRows--;
    }
});

   jQuery(document).ready(function($) {
    var $townInput = $("#town");
    var $townHidden = $("#town_id");

    if ($townInput.val() && !$townHidden.val()) {
        $.post(ajaxurl, {
            action: "speedy_get_sites",
            name: $townInput.val()
        }, function(response) {
            if (response && response.length) {
                // търсим точен мач
                var match = response.find(function(site) {
                    return site.name.toLowerCase() === $townInput.val().toLowerCase();
                });

                // ако няма точен мач → вземаме първия резултат
                if (!match) {
                    match = response[0];
                    console.warn("Няма точен мач, попълваме първия:", match.name);
                }

                if (match) {
                    $townHidden.val(match.id);
                    console.log("Попълних town_id:", match.id, match.name);
                }
            } else {
                console.warn("Speedy не върна резултати за:", $townInput.val());
            }
        }).fail(function(xhr, status, error) {
            console.error("Грешка при зареждане на town_id:", status, error, xhr.responseText);
        });
    }
});



jQuery(document).ready(function($) {
    var $input = $("#town");
    var $hidden = $("#town_id");
    var $list = $("#town-suggestions");

    var selectedIndex = -1;

    function renderSuggestions(response) {
        $list.empty();
        selectedIndex = -1;

        if (response && response.length) {
            response.forEach(function(site) {
                var $item = $("<li>")
                    .text(site.name)
                    .attr("data-id", site.id)
                    .css({"cursor":"pointer","padding":"3px"})
                    .on("click", function() {
                        selectItem(site);
                    });
                $list.append($item);
            });
            $list.show();
        } else {
            $list.append("<li style=\'color:#999;padding:3px;\'>Няма резултати</li>").show();
        }
    }

    function selectItem(site) {
        $input.val(site.name);
        $hidden.val(site.id);
        $list.empty().hide();
        selectedIndex = -1;
    }

    $input.on("input", function() {
        var query = $(this).val();

        if (query.length < 3) {
            $list.empty().hide();
            return;
        }

        $.post(ajaxurl, {
            action: "speedy_get_sites",
            name: query
        }, function(response) {
            console.log("Speedy response:", response);
            renderSuggestions(response);
        }).fail(function(xhr, status, error) {
            console.error("AJAX error:", status, error, xhr.responseText);
        });
    });

    $input.on("keydown", function(e) {
        var $items = $list.find("li");
        if (!$items.length) return;

        if (e.key === "ArrowDown") {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % $items.length;
            highlightItem($items);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
            highlightItem($items);
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (selectedIndex >= 0) {
                var $selected = $items.eq(selectedIndex);
                selectItem({
                    id: $selected.data("id"),
                    name: $selected.text()
                });
            }
        }
    });

    function highlightItem($items) {
        $items.css("background", "").css("color", "");
        if (selectedIndex >= 0) {
            $items.eq(selectedIndex).css({"background":"#007BFF","color":"#fff"});
        }
    }

    $(document).on("click", function(e) {
        if(!$(e.target).closest("#town, #town-suggestions").length){
            $list.empty().hide();
            selectedIndex = -1;
        }
    });
});
</script>
                <br>
                <div class="address-details-fields" ' . $address_fields_style . '>

               <label>Квартал:</label>
    <input type="text" id="complexId" name="complexId" value="'.htmlspecialchars($shipping_neighborhood).'" autocomplete="off" style="width:300px;margin:5px;">
    <input type="hidden" id="complex_id_hidden" name="complex_id_hidden">
    <ul id="complex-suggestions" style="border:1px solid #ccc; max-height:200px; overflow:auto; list-style:none; padding:5px; margin-top:2px; width:300px; display:none;"></ul>
</div>
    <script type="text/javascript">
jQuery(document).ready(function($) {
    var $input = $("#complexId");
    var $hidden = $("#complex_id_hidden");
    var $list = $("#complex-suggestions");
    var $townId = $("#town_id");

    var selectedIndex = -1;

    function renderSuggestions(response) {
        $list.empty();
        selectedIndex = -1;

        if (response && response.length) {
            response.forEach(function(complex) {
                var $item = $("<li>")
                    .text(complex.name)
                    .attr("data-id", complex.id)
                    .css({"cursor":"pointer","padding":"3px"})
                    .on("click", function() {
                        selectItem(complex);
                    });
                $list.append($item);
            });
            $list.show();
        } else {
            $list.append("<li style=\'color:#999;padding:3px;\'>Няма резултати</li>").show();
        }
    }

    function selectItem(complex) {
        $input.val(complex.name);
        $hidden.val(complex.id);
        $list.empty().hide();
        selectedIndex = -1;
    }

    $input.on("input", function() {
        var query = $(this).val();
        var siteId = $townId.val();

        if (!siteId || query.length < 2) {
            $list.empty().hide();
            return;
        }

        $.post(ajaxurl, {
            action: "speedy_get_complexes",
            siteId: siteId,
            name: query
        }, function(response) {
            console.log("Speedy complexes response:", response);
            renderSuggestions(response);
        }).fail(function(xhr, status, error) {
            console.error("AJAX error:", status, error, xhr.responseText);
        });
    });

    $input.on("keydown", function(e) {
        var $items = $list.find("li");
        if (!$items.length) return;

        if (e.key === "ArrowDown") {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % $items.length;
            highlightItem($items);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
            highlightItem($items);
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (selectedIndex >= 0) {
                var $selected = $items.eq(selectedIndex);
                selectItem({
                    id: $selected.data("id"),
                    name: $selected.text()
                });
            }
        }
    });

    function highlightItem($items) {
        $items.css("background", "").css("color", "");
        if (selectedIndex >= 0) {
            $items.eq(selectedIndex).css({"background":"#007BFF","color":"#fff"});
        }
    }

    $(document).on("click", function(e) {
        if(!$(e.target).closest("#complexId, #complex-suggestions").length){
            $list.empty().hide();
            selectedIndex = -1;
        }
    });
});
</script>
                
                <br>
                
                              <label>' . $address_label . '</label>

    <input type="text" id="streetId" name="streetId" value="'.htmlspecialchars($shipping_street).'" autocomplete="off" style="width:300px;">
    <input type="hidden" id="street_id_hidden" name="street_id_hidden">
    <ul id="street-suggestions" style="border:1px solid #ccc; max-height:200px; overflow:auto; list-style:none; padding:5px; margin-top:2px; width:300px; display:none;"></ul>
';

$note_label = ( $is_foreign_order && $billing_shipping_type === 'address' )
    ? 'Пощенски код:'
    : 'Уточнение:';

$note_placeholder = ( $is_foreign_order && $billing_shipping_type === 'address' )
    ? 'Въведете пощенски код...'
    : 'Допълнителна информация...';
if ($is_foreign_order && !$is_romania_order) {
    echo '
    <br>
    <label>Адрес 2:</label>
    <input type="text" id="streetId2" name="streetId2" value="'.htmlspecialchars($shipping_street2).'" autocomplete="off" style="width:300px;">';
}

echo '
    <script type="text/javascript">
jQuery(document).ready(function($) {
    var $input = $("#streetId");
    var $hidden = $("#street_id_hidden");
    var $list = $("#street-suggestions");
    var $townId = $("#town_id");

    var selectedIndex = -1;

    function renderSuggestions(response) {
        $list.empty();
        selectedIndex = -1;

        if (response && response.length) {
            response.forEach(function(street) {
                var $item = $("<li>")
                    .text(street.name)
                    .attr("data-id", street.id)
                    .css({"cursor":"pointer","padding":"3px"})
                    .on("click", function() {
                        selectItem(street);
                    });
                $list.append($item);
            });
            $list.show();
        } else {
            $list.append("<li style=\'color:#999;padding:3px;\'>Няма резултати</li>").show();
        }
    }

    function selectItem(street) {
        $input.val(street.name);
        $hidden.val(street.id);
        $list.empty().hide();
        selectedIndex = -1;
    }

    $input.on("input", function() {
        var query = $(this).val();
        var siteId = $townId.val();

        if (!siteId || query.length < 2) {
            $list.empty().hide();
            return;
        }

        $.post(ajaxurl, {
            action: "speedy_get_streets",
            siteId: siteId,
            name: query
        }, function(response) {
            console.log("Speedy streets response:", response);
            renderSuggestions(response);
        }).fail(function(xhr, status, error) {
            console.error("AJAX error:", status, error, xhr.responseText);
        });
    });

    $input.on("keydown", function(e) {
        var $items = $list.find("li");
        if (!$items.length) return;

        if (e.key === "ArrowDown") {
            e.preventDefault();
            selectedIndex = (selectedIndex + 1) % $items.length;
            highlightItem($items);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
            highlightItem($items);
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (selectedIndex >= 0) {
                var $selected = $items.eq(selectedIndex);
                selectItem({
                    id: $selected.data("id"),
                    name: $selected.text()
                });
            }
        }
    });

    function highlightItem($items) {
        $items.css("background", "").css("color", "");
        if (selectedIndex >= 0) {
            $items.eq(selectedIndex).css({"background":"#007BFF","color":"#fff"});
        }
    }

    $(document).on("click", function(e) {
        if(!$(e.target).closest("#streetId, #street-suggestions").length){
            $list.empty().hide();
            selectedIndex = -1;
        }
    });
});

jQuery(document).ready(function($) {
    var $deliveryType = $("#doofisaddress");
    var $speedyAddress1 = $("#streetId");
    var $speedyAddress2 = $("#streetId2");
    var $billingAddress1 = $("#_billing_address_1");
    var $billingAddress2 = $("#_billing_address_2");

    function syncBillingAddressFields() {
        if (!$billingAddress1.length || !$billingAddress2.length) {
            return;
        }

        if ($deliveryType.length && $deliveryType.val() !== "2") {
            return;
        }

        $billingAddress1.val(($speedyAddress1.val() || "").trim()).trigger("change");

        if ($speedyAddress2.length) {
            $billingAddress2.val(($speedyAddress2.val() || "").trim()).trigger("change");
        }
    }

    syncBillingAddressFields();

    $speedyAddress1.on("input change", syncBillingAddressFields);
    $speedyAddress2.on("input change", syncBillingAddressFields);
    $deliveryType.on("change", syncBillingAddressFields);
});
</script>


                                <div class="address-details-fields" ' . $address_fields_style . '>

                <label>№:</label>
                <input type="text" id="streetNo" style="width:70px;" name="streetNo" value="'.htmlspecialchars($shipping_street_number).'">
                
              <br>
                
                <label>Бл.:</label>
                <input type="text" id="blockNo" style="width:70px;margin: 8px;" name="blockNo" value="'.htmlspecialchars($shipping_block).'">
                
                <label>Вх.:</label>
                <input type="text" id="entranceNo" style="width:70px;" name="entranceNo" value="'.htmlspecialchars($shipping_entrance).'">
                
                <label>Ет.:</label>
                <input type="text" id="floorNo" style="width:70px;" name="floorNo" value="'.htmlspecialchars($shipping_floor).'">
                
                <label>Ап.:</label>
                <input type="text" id="apartmentNo" name="apartmentNo" style="width:70px;" value="'.htmlspecialchars($shipping_apartment).'">
                 </div>
                <br>
            </td>
          </tr>

         <script>
    const billingShippingType = "' . $billing_shipping_type . '";

    document.getElementById("doofisaddress").addEventListener("change", function () {
        const value = this.value;
        const officered = document.getElementById("officered");
        const addressred = document.getElementById("addressred");

        if (value === "2") {
            officered.style.display = "none";
            addressred.style.display = "";
        } else {
            officered.style.display = "";
            addressred.style.display = "none";
        }
    });

    window.addEventListener("DOMContentLoaded", function () {
        const officered = document.getElementById("officered");
        const addressred = document.getElementById("addressred");
        const deliveryType = document.getElementById("doofisaddress");

        if (billingShippingType === "address") {
            officered.style.display = "none";
            addressred.style.display = "";
            deliveryType.value = "2";
        } else if (billingShippingType === "office2") {
            officered.style.display = "";
            addressred.style.display = "none";
            deliveryType.value = "0";
        } else {
            officered.style.display = "";
            addressred.style.display = "none";
            deliveryType.value = "1";
        }
    });
</script>';

if ( ! ( $is_foreign_order && $billing_shipping_type === 'address' ) ) {
echo '
<tr>
<td><label for="note">Уточнение:</label></td>
<td>
<textarea id="note" name="note" rows="2" cols="40" placeholder="Допълнителна информация...">' . $address2 . '</textarea>
</td>
</tr>';
}
$declared_value_readonly = $obqvena_enabled ? '' : ' readonly="readonly"';
echo '

  
<tr>
    <td><label for="cod_amount" class="speedy_required">Наложен платеж (' . esc_html($currency) . '):</label></td>
    <td>
        <input type="text" id="cod_amount" name="cod_amount" value="' . $cod_amount_display . '" />
        <span id="cod_amount_eur" style="display:none!important;color:#888;font-size:0.9em;margin-left:5px;"></span>
        <?php
' . (
    $cod_amount_converted && $target_currency
        ? '<div style="color:#888;font-size:0.9em;margin-top:2px;">≈ ' . esc_html($cod_amount_converted) . ' ' . esc_html($target_currency) . '</div>'
        : ''
) . '
       
    </td>
</tr>
<tr>
    <td><label for="declared_value" class="speedy_required">Обявена стойност (' . esc_html($currency) . '):</label></td>
    <td>
<input type="text" id="declared_value" name="declared_value" value="' . $declared_value_display . '"' . $declared_value_readonly . ' />        <span id="declared_value_eur" style="display:none!important;color:#888;font-size:0.9em;margin-left:5px;"></span>
    </td>
</tr>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const rate = 1.9548;

    function convertBGNtoEUR(inputId, outputId) {
        const input = document.getElementById(inputId);
        const output = document.getElementById(outputId);
        if (input && output) {
            const value = parseFloat(input.value.replace(",", "."));
            if (!isNaN(value)) {
                const eur = (value / rate).toFixed(2);
                output.textContent = "/ " + eur + " €";
            } else {
                output.textContent = "";
            }
        }
    }

    convertBGNtoEUR("cod_amount", "cod_amount_eur");
    convertBGNtoEUR("declared_value", "declared_value_eur");

    document.getElementById("cod_amount").addEventListener("input", function () {
        convertBGNtoEUR("cod_amount", "cod_amount_eur");
    });

    document.getElementById("declared_value").addEventListener("input", function () {
        convertBGNtoEUR("declared_value", "declared_value_eur");
    });
});
</script>


       
       
    </table>
        </div>
<style>td{text-align:left;}</style>
        ';
    }

    echo '<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".woocommerce-Price-amount").forEach(el => {
      const text = el.innerText.trim();
      const match = text.match(/^([\\d.,]+)\\s*лв\\./);

      if (match) {
        const bgn = parseFloat(match[1].replace(",", "."));
        const eur = (bgn / 1.9548).toFixed(2);

        if (!el.innerText.includes("€")) {
          //el.innerHTML += " / " + eur + " €";
        }
      }
    });
  });
</script>';


$amount = wc_format_decimal($order->get_subtotal(), 2);
$free_shipping_amount_base = speedy_get_order_products_total_with_vat( $order );
$weight = isset($_GET['weight']) ? (float) $_GET['weight'] : (float) speedy_get_setting('teglo');

if ( isset($_GET['weight']) && $_GET['weight'] !== '' ) {
    $weight = (float) $_GET['weight'];
} else {
    $order_weight = 0;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product ) {
            $product_weight = (float) $product->get_weight();
            $quantity = (int) $item->get_quantity();
            $order_weight += $product_weight * $quantity;
        }
    }

    // Ако е сметнато тегло от продуктите
    if ( $order_weight > 0 ) {
        $weight = wc_get_weight( $order_weight, get_option('woocommerce_weight_unit') );
    } else {
        // fallback към настройката
        $weight = (float) speedy_get_setting('teglo');
    }
}


$clientidvalue = isset($_GET['clientid']) ? $_GET['clientid'] : speedy_get_setting('sender_id');

$delivery_type = null;

if (isset($_GET['doofisaddress'])) {
    $delivery_type = (int) $_GET['doofisaddress'];
}
$speedy_country_id = (int) $order->get_meta('_shipping_shipping_country');
$drop = (int) $order->get_meta('_billing_office');

if ($speedy_country_id > 0 && $speedy_country_id !== 100) {
    $abroad_drop = (int) $order->get_meta('_billing_abroadoffice_id');
    if ($abroad_drop > 0) {
        $drop = $abroad_drop;
    }
}

 $billing_shipping_type  = $order->get_meta('_billing_shipping_type');
 $billing_shipping_type  = self::normalize_admin_shipping_type(
    $billing_shipping_type,
    $delivery_type,
    $speedy_country_id > 0 && $speedy_country_id !== 100,
    (int) $order->get_meta('_billing_abroadoffice_id'),
    (int) $order->get_meta('_billing_office')
 );
            $billing_address_index  = $order->get_meta('_billing_address_index');
            $shipping_address_index = $order->get_meta('_shipping_address_index');

             $shipping_neighborhood   = $order->get_meta('_shipping_neighborhood');
            $shipping_street         = $order->get_meta('_shipping_street');
            $shipping_street_number  = $order->get_meta('_shipping_street_number');
            $shipping_block          = $order->get_meta('_shipping_block');
            $shipping_entrance       = $order->get_meta('_shipping_entrance');
            $shipping_floor          = $order->get_meta('_shipping_floor');
            $shipping_apartment      = $order->get_meta('_shipping_apartment');

            $shippingSiteId = null;

           if (preg_match_all('/\s(\d+)(?=\s|$)/', $shipping_address_index, $matches)) {
                $shippingSiteId = end($matches[1]);
            }

            $town = $complexId = $streetId = $streetNo = $blockNo = $entranceNo = $floorNo = $apartmentNo = "";

        if (preg_match('/(гр\.|с\.)\s*([^\s\d]+)/u', $billing_address_index, $matches)) {
            $town = trim($matches[2]);
        }
            if (preg_match('/ул\.?\s*([^\d]+)\s*(\d+)?/u', $billing_address_index, $matches)) {
                $streetId = trim($matches[1]);
                $streetNo = isset($matches[2]) ? $matches[2] : "";
            }
            if (preg_match('/ап\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $apartmentNo = $matches[1];
            }
            if (preg_match('/ет\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $floorNo = $matches[1];
            }
            if (preg_match('/(вх\.|вход)\s*([^\s]+)/u', $billing_address_index, $matches)) {
                $entranceNo = $matches[2];
            }
            if (preg_match('/бл\.?\s*(\d+)/u', $billing_address_index, $matches)) {
                $blockNo = $matches[1];
            }

if (isset($_GET['drop'])) {
    $drop = (int) $_GET['drop'];
}

if (isset($_GET['complexId'])) {
    $complexId = $_GET['complexId'];
}
if (isset($_GET['streetId'])) {
    $streetId = $_GET['streetId'];
}
if (isset($_GET['streetNo'])) {
    $streetNo = $_GET['streetNo'];
}
if (isset($_GET['blockNo'])) {
    $blockNo = $_GET['blockNo'];
}
if (isset($_GET['entranceNo'])) {
    $entranceNo = $_GET['entranceNo'];
}
if (isset($_GET['floorNo'])) {
    $floorNo = $_GET['floorNo'];
}
if (isset($_GET['apartmentNo'])) {
    $apartmentNo = $_GET['apartmentNo'];
}
if (isset($_GET['town'])) {
    $town = $_GET['town'];
}

$sender_officeyesno = speedy_get_setting( 'sender_officeyesno' );
$sender_office_id = speedy_get_setting( 'sender_office' );
$services_text = speedy_get_setting('uslugitext');
$service_ids = array_map('intval', explode(',', $services_text));
        $arr_data = [
           'userName' => speedy_username(),
        'password' => speedy_password(),
            'language' => 'BG',
            'sender' => [
                
            ],
            'recipient' => [
                'privatePerson' => true,
            ],
            'service' => [
                'autoAdjustPickupDate' => true,
                'additionalServices'    => [
                    'cod' => [
                       
                    ],
                ],
                'serviceIds' => $service_ids,
            ],
            'content' => [
                'parcelsCount' => 1,
                'totalWeight'  => $weight,
            ],
            'payment' => [
                'courierServicePayer' => 'RECIPIENT',
            ],
        ];

        
$widths  = isset($_GET['widths']) ? explode(',', (string) wp_unslash($_GET['widths'])) : [];
$lengths = isset($_GET['lengths']) ? explode(',', (string) wp_unslash($_GET['lengths'])) : [];
$heights = isset($_GET['heights']) ? explode(',', (string) wp_unslash($_GET['heights'])) : [];
$weights = isset($_GET['weights']) ? explode(',', (string) wp_unslash($_GET['weights'])) : [];

$arr_data['content']['parcels'] = [];

// 1) Ръчни размери имат приоритет
if (!empty($widths) && !empty($lengths) && !empty($heights) && !empty($weights)) {
    $cnt = min(count($widths), count($lengths), count($heights), count($weights));

    for ($i = 0; $i < $cnt; $i++) {
        $w  = (float) trim((string) $widths[$i]);
        $d  = (float) trim((string) $lengths[$i]);
        $h  = (float) trim((string) $heights[$i]);
        $pw = (float) trim((string) $weights[$i]);

        if ($w > 0 && $d > 0 && $h > 0 && $pw > 0) {
            $arr_data['content']['parcels'][] = [
                'seqNo' => $i + 1,
                'size' => [
                    'width'  => $w,
                    'depth'  => $d,
                    'height' => $h,
                ],
                'weight' => $pw,
            ];
        }
    }
}

// 2) Ако няма ръчни -> най-големият продукт (по обем), за всички държави
if (empty($arr_data['content']['parcels'])) {
    $parcel_width  = 0.0;
    $parcel_depth  = 0.0;
    $parcel_height = 0.0;
    $max_volume    = 0.0;

    $pick_largest_dimensions = static function ($product) use (&$parcel_width, &$parcel_depth, &$parcel_height, &$max_volume) {
        if (!$product || !is_object($product)) {
            return;
        }

        $w = (float) $product->get_width();
        $d = (float) $product->get_length();
        $h = (float) $product->get_height();

        if ($w <= 0 || $d <= 0 || $h <= 0) {
            return;
        }

        $volume = $w * $d * $h;
        if ($volume > $max_volume) {
            $max_volume    = $volume;
            $parcel_width  = $w;
            $parcel_depth  = $d;
            $parcel_height = $h;
        }
    };

    foreach ($order->get_items('line_item') as $item) {
        $product = $item->get_product();
        $pick_largest_dimensions($product);

        if (
            $product &&
            is_object($product) &&
            method_exists($product, 'is_type') &&
            $product->is_type('variation')
        ) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                $pick_largest_dimensions($parent);
            }
        }
    }

    if ($parcel_width > 0 && $parcel_depth > 0 && $parcel_height > 0) {
        $arr_data['content']['parcels'][] = [
            'seqNo' => 1,
            'size' => [
                'width'  => $parcel_width,
                'depth'  => $parcel_depth,
                'height' => $parcel_height,
            ],
            'weight' => (float) $weight,
        ];
    }
}

if (!empty($arr_data['content']['parcels'])) {
    $arr_data['content']['parcelsCount'] = count($arr_data['content']['parcels']);
} else {
    unset($arr_data['content']['parcels']);
    $arr_data['content']['parcelsCount'] = 1;
}

        $processing_type = 'CASH';
        
$processing_surcharge_with_vat = 0.0;
if (speedy_get_setting('cenadostavka') === 'nadbavka') {
    $processing_surcharge_with_vat = max(0, (float) speedy_get_setting('suma_nadbavka'));
}
            $arr_data['service']['additionalServices']['cod'] = [
                'amount' => $amount,
                'processingType' => $processing_type,
            ];

           $mode = speedy_get_setting('moneytransfer');


            //
            // --- FISCAL MODE (всички продукти по отделно)
            //
            if ( $mode === 'fiscal' ) {

                $fiscal_items = [];

                foreach ( $order->get_items() as $item_id => $item ) {

                    $product = $item->get_product();
                    $name    = $item->get_name();
                    $qty     = $item->get_quantity();

                    // добавяме (x qty) ако количеството е > 1
                    $suffix = $qty > 1 ? " (x{$qty})" : "";

                    // Описание максимум 50 символа
                    // първо правим името, после режем
                    $description = mb_substr( $name . $suffix, 0, 50 );

                    // Определяме ДДС група
                    $tax_class = $product->get_tax_class();
                    $vat_group = 'Б';
                    $vat_rate  = 0.20;

                    if ( $tax_class === 'zero-rate' ) {
                        $vat_group = 'А';
                        $vat_rate  = 0.00;
                    } elseif ( $tax_class === 'reduced-rate' ) {
                        $vat_group = 'Г';
                        $vat_rate  = 0.09;
                    }

                    // WooCommerce цената е крайна с ДДС
                    $price_incl_vat = (float) $order->get_item_subtotal( $item, true, false );

                    // Цена без ДДС
                    $price_excl_vat = $vat_rate > 0
                        ? $price_incl_vat / (1 + $vat_rate)
                        : $price_incl_vat;

                    $fiscal_items[] = [
                        'description'   => $description,
                        'vatGroup'      => $vat_group,
                        'amount'        => round( $price_excl_vat * $qty, 2 ),
                        'amountWithVat' => round( $price_incl_vat * $qty, 2 ),
                    ];
                }

                
                $arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
            }



            //
            // --- FISCALONE MODE (групиране по ДДС групи)
            //
            if ( $mode === 'fiscalone' ) {

                // Инициализация на групите
                $groups = [
                    'А' => ['ex' => 0, 'in' => 0], // 0%
                    'Г' => ['ex' => 0, 'in' => 0], // 9%
                    'Б' => ['ex' => 0, 'in' => 0], // 20%
                ];

                foreach ( $order->get_items() as $item_id => $item ) {

                    $product = $item->get_product();
                    $qty     = $item->get_quantity();

                    // Определяме ДДС клас и ставка
                    $tax_class = $product->get_tax_class();
                    $vat_group = 'Б';
                    $vat_rate  = 0.20;

                    if ( $tax_class === 'zero-rate' ) {
                        $vat_group = 'А';
                        $vat_rate  = 0.00;
                    } elseif ( $tax_class === 'reduced-rate' ) {
                        $vat_group = 'Г';
                        $vat_rate  = 0.09;
                    }

                    // WooCommerce цена с ДДС
                    $price_incl_vat = (float) $order->get_item_subtotal( $item, true, false );

                    // Цена без ДДС
                    $price_excl_vat = $vat_rate > 0
                        ? $price_incl_vat / (1 + $vat_rate)
                        : $price_incl_vat;

                    // Натрупване в групата
                    $groups[$vat_group]['ex'] += $price_excl_vat * $qty;
                    $groups[$vat_group]['in'] += $price_incl_vat * $qty;
                }

                // Генерираме по 1 ред за всяка група с ненулева сума
                $fiscal_items = [];

                foreach ($groups as $group => $sum) {
                    if ($sum['in'] <= 0) continue;

                    $fiscal_items[] = [
                        'description'   => 'Продукти от поръчка №' . $order->get_id() . " (група $group)",
                        'vatGroup'      => $group,
                        'amount'        => round($sum['ex'], 2),
                        'amountWithVat' => round($sum['in'], 2),
                    ];
                }

            
                $arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
            }



           
$includes_shipping_in_cod = ( speedy_get_setting('includeshippingprice') === 'YES' );

$free_shipping         = speedy_get_setting('free_shipping');
$free_shipping_address = floatval(speedy_get_setting('free_shipping_address'));
$free_shipping_office  = floatval(speedy_get_setting('free_shipping_office'));
$free_shipping_automat = floatval(speedy_get_setting('free_shipping_automat'));

$free_shipping_applies = (
    $free_shipping == 'yes'
    && (
        ($billing_shipping_type === 'office' && $free_shipping_amount_base >= $free_shipping_office)
        || ($billing_shipping_type === 'address' && $free_shipping_amount_base >= $free_shipping_address)
        || ($billing_shipping_type === 'office2' && $free_shipping_amount_base >= $free_shipping_automat)
    )
);

$uses_non_speedy_shipping_price = $free_shipping_applies;

if ( $includes_shipping_in_cod && ! $uses_non_speedy_shipping_price ) {
    $arr_data['service']['additionalServices']['cod']['includeShippingPrice'] = true;
}

if ( $includes_shipping_in_cod ) {
    $arr_data['payment'] = [
        'courierServicePayer' => 'SENDER'
    ];
} elseif ( $free_shipping == 'yes' ) {
    if ( $free_shipping_applies ) {
        $arr_data['payment'] = [
            'courierServicePayer' => 'SENDER'
        ];
    } else {
        $arr_data['payment'] = [
            'courierServicePayer' => 'RECIPIENT'
        ];
    }
} else {
    $arr_data['payment'] = [
        'courierServicePayer' => 'RECIPIENT'
    ];
}


        if (speedy_get_setting('dopalnitelni')) {
                $arr_data['service']['additionalServices']['specialDeliveryId'] = speedy_get_setting('dopalnitelni');
            }


            // Include administrativeFee
        if (speedy_get_setting('administrative') == 'YES') {
             $arr_data['payment']['administrativeFee'] = true;
        }



        if (
    speedy_get_setting('test_before_pay') == 'TEST' 
    && ($billing_shipping_type != 'office2' || speedy_get_setting('autoclose') == 'NO')
) {
          
                $arr_data['service']['additionalServices']['obpd'] = [
                   'option' => 'TEST', 
                   'returnShipmentServiceId' => 505, 
                   'returnShipmentPayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY). The sender of the returning shipment is the recipient of the primary shipment.
                ];
            
        }

       if (
    speedy_get_setting('test_before_pay') == 'OPEN' 
    && ($billing_shipping_type != 'office2' || speedy_get_setting('autoclose') == 'NO')
) {
          
                $arr_data['service']['additionalServices']['obpd'] = [
                   'option' => 'OPEN', 
                   'returnShipmentServiceId' => 505, 
                   'returnShipmentPayer' => 'SENDER' // (SENDER, RECIPIENT, THIRD_PARTY). The sender of the returning shipment is the recipient of the primary shipment.
                ];
            
        }

        if (speedy_get_setting('vaucher') == 'YES') {
                $arr_data['service']['additionalServices']['returns']['returnVoucher']['serviceId'] = 505;
                $arr_data['service']['additionalServices']['returns']['returnVoucher']['payer'] = speedy_get_setting('vaucherpayer');
                 $voucherPayerDays = speedy_get_setting('vaucherpayerdays');
                if (!empty($voucherPayerDays)) {
                    $arr_data['service']['additionalServices']['returns']['returnVoucher']['validityPeriod'] = $voucherPayerDays;
                }
            }

         if( speedy_get_setting('saturdayoption') == 'YES' ) {
            $arr_data['service']['saturdayDelivery'] = true;
        }

         if ($sender_officeyesno === 'YES') {
            $arr_data['sender']['dropoffOfficeId'] = $sender_office_id;
            $arr_data['sender']['clientId'] = $clientidvalue;
        }
        else{
                $arr_data['sender']['clientId'] = $clientidvalue;
        }

       if ($billing_shipping_type == 'office') {
            // Ако е избрана доставка до офис
            $arr_data['recipient']['pickupOfficeId'] = $drop;
        }
        else if ($billing_shipping_type == 'office2') {
            // Ако е избрана доставка до офис
            $arr_data['recipient']['pickupOfficeId'] = $drop;
        }
        else if ($billing_shipping_type == 'Офис') {
            // Ако е избрана доставка до офис
            $arr_data['recipient']['pickupOfficeId'] = $drop;
        }
        else {
            // Ако е избрана доставка до адрес
             $shipping_address_indexnew = $order->get_meta('_shipping_city');
             $countryID = (int) $order->get_meta('_shipping_shipping_country');

            if ($countryID <= 0 && method_exists($order, 'get_billing_country')) {
                $billing_iso2 = strtoupper(trim((string) $order->get_billing_country()));

                if ($countryID <= 0 && $billing_iso2 !== '' && function_exists('speedy_country_info_fallback_by_iso')) {
                    $country_info = speedy_country_info_fallback_by_iso($billing_iso2);
                    if (is_array($country_info) && !empty($country_info['id'])) {
                        $countryID = (int) $country_info['id'];
                    }
                }
            }

            if ($countryID <= 0) {
                $countryID = 100;
            }

            $arr_data['recipient']['addressLocation'] = [
                    'countryId' => $countryID,
                'siteId'       => $shipping_address_indexnew
            ];
        }

        $fixed_shipping           = speedy_get_setting('fixed_shipping');
        $fixed_shipping_address   = floatval(speedy_get_setting('fixed_shipping_address'));
        $fixed_shipping_office    = floatval(speedy_get_setting('fixed_shipping_office'));
        $fixed_shipping_automat   = floatval(speedy_get_setting('fixed_shipping_automat'));

        $fixed_shipping         = speedy_get_setting('fixed_shipping');
        $fixed_shipping_address = floatval(speedy_get_setting('fixed_shipping_address'));
        $fixed_shipping_office  = floatval(speedy_get_setting('fixed_shipping_office'));
        $fixed_shipping_automat = floatval(speedy_get_setting('fixed_shipping_automat'));

        $requested_service_id = (int) $order->get_meta('_speedy_selected_service_id');
        if ($requested_service_id <= 0 && isset($_REQUEST['speedy_selected_service_id'])) {
            $requested_service_id = (int) sanitize_text_field(wp_unslash($_REQUEST['speedy_selected_service_id']));
        }

        $recipient_country_id = 0;
        if (isset($arr_data['recipient']['addressLocation']['countryId'])) {
            $recipient_country_id = (int) $arr_data['recipient']['addressLocation']['countryId'];
        } elseif (method_exists($order, 'get_meta')) {
            $recipient_country_id = (int) $order->get_meta('_shipping_shipping_country');
        }

        $billing_country_iso2 = '';
        if (method_exists($order, 'get_billing_country')) {
            $billing_country_iso2 = strtoupper(trim((string) $order->get_billing_country()));
        }

        $is_bg_destination = $recipient_country_id === 100 || $billing_country_iso2 === 'BG';

        $destination_request = [
            'userName'  => speedy_username(),
            'password'  => speedy_password(),
            'date'      => date('Y-m-d'),
            'recipient' => $arr_data['recipient'],
        ];

        $destination_response = WS_Speedy_Request::call(
            SPEEDY_API_BASE_URL . 'services/destination',
            $destination_request
        );

        $allowed_service_ids = [];
        $destination_cod_policies = self::get_destination_cod_policies($destination_response);
        $destination_obpd_policies = self::get_destination_obpd_policies($destination_response);

        if (
            is_array($destination_response)
            && !empty($destination_response['services'])
            && is_array($destination_response['services'])
        ) {
            foreach ($destination_response['services'] as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $destination_service_id = self::get_destination_service_id($service);
                if ($destination_service_id > 0) {
                    $allowed_service_ids[] = $destination_service_id;
                }
            }
        }

        $allowed_service_ids = array_values(array_unique(array_filter($allowed_service_ids)));

        if ($requested_service_id > 0 && !in_array($requested_service_id, $allowed_service_ids, true)) {
            $requested_service_id = 0;
        }

        if ($requested_service_id > 0) {
            $arr_data['service']['serviceIds'] = [$requested_service_id];
        } elseif (!empty($allowed_service_ids)) {
            $arr_data['service']['serviceIds'] = $allowed_service_ids;
        }

        $is_cod_payment = method_exists($order, 'get_payment_method')
            && $order->get_payment_method() === 'cod';
        $skip_calculate_request = false;

        if ($is_cod_payment) {
            $moneytransfer_mode = (string) speedy_get_setting('moneytransfer');
            $cod_resolution = self::resolve_cod_processing_for_destination(
                $destination_cod_policies,
                $requested_service_id,
                $moneytransfer_mode,
                $is_bg_destination
            );

            if ($cod_resolution['status'] === 'forbidden') {
                $delivery_price = $cod_resolution['message'];
                $skip_calculate_request = true;
            }

            if (
                isset($arr_data['service']['additionalServices']['cod'])
                && is_array($arr_data['service']['additionalServices']['cod'])
                && !empty($cod_resolution['processingType'])
            ) {
                $arr_data['service']['additionalServices']['cod']['processingType'] = $cod_resolution['processingType'];
            }
        }

        if (isset($arr_data['service']['additionalServices']['obpd'])) {
            $should_send_obpd = self::should_send_obpd_for_destination(
                $destination_obpd_policies,
                $requested_service_id
            );

            if (!$should_send_obpd) {
                unset($arr_data['service']['additionalServices']['obpd']);
            } elseif (!$is_bg_destination) {
                $obpd_return_service_id = $requested_service_id > 0
                    ? $requested_service_id
                    : (!empty($allowed_service_ids) ? (int) $allowed_service_ids[0] : 0);

                if ($obpd_return_service_id > 0) {
                    $arr_data['service']['additionalServices']['obpd']['returnShipmentServiceId'] = $obpd_return_service_id;
                }
            }
        }

        if ($waybill === NULL) {

            if (!$skip_calculate_request) {

            if ($fixed_shipping === 'yes') {
                $uses_non_speedy_shipping_price = true;

                if ($billing_shipping_type === 'office') {
                    $delivery_price = number_format($fixed_shipping_office, 2);
                    $deliverycurrency = "BGN";

                    if ($is_cod_payment) {
                        $arr_data['service']['additionalServices']['cod']['amount'] = round($amount + $fixed_shipping_office, 2);
                    }
                } elseif ($billing_shipping_type === 'office2') {
                    $delivery_price = number_format($fixed_shipping_automat, 2);
                    $deliverycurrency = "BGN";

                    if ($is_cod_payment) {
                        $arr_data['service']['additionalServices']['cod']['amount'] = round($amount + $fixed_shipping_automat, 2);
                    }
                } elseif ($billing_shipping_type === 'address') {
                    $delivery_price = number_format($fixed_shipping_address, 2);
                    $deliverycurrency = "BGN";

                    if ($is_cod_payment) {
                        $arr_data['service']['additionalServices']['cod']['amount'] = round($amount + $fixed_shipping_address, 2);
                    }
                } else {
                    $delivery_price = 'Грешен тип доставка';
                }

                if (isset($arr_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
                    unset($arr_data['service']['additionalServices']['cod']['includeShippingPrice']);
                }
            }
            else if(speedy_get_setting('cenadostavka') === 'fileprices') {
                $uses_non_speedy_shipping_price = true;
                $deliverycurrency = "BGN";
                    $file_path = get_option('speedy_fileceni_path');

                    $arr_data['payment'] = [
                        'courierServicePayer' => 'SENDER'
                    ];

                    if ($file_path && file_exists($file_path)) {
                        // Определяме TakeFromOffice според shipping type
                        $take_from_office = 0;
                        if ($billing_shipping_type === 'office') {
                            $take_from_office = 1;
                        } elseif ($billing_shipping_type === 'office2') {
                            $take_from_office = 2;
                        } elseif ($billing_shipping_type === 'address') {
                            $take_from_office = 0;
                        }

                        // Стойността на продуктите
                        $amount = floatval(wc_format_decimal($order->get_subtotal(), 2));

                        // Теглото на поръчката
                        $order_weight = $weight;

                        if (($handle = fopen($file_path, "r")) !== false) {
                            fgetcsv($handle); // пропускаме header реда

                            $best_fit_price = null;
                            $best_fit_order_total = null;

                            while (($data = fgetcsv($handle)) !== false) {
                                list($service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price) = $data;

                                if (
                                    self::normalize_fileprice_delivery_target( $csv_take_from_office ) === $take_from_office &&
                                    $order_weight <= floatval($csv_weight) &&
                                    $amount <= floatval($csv_order_total)
                                ) {
                                    // Вземаме реда с най-малък OrderTotal, който покрива поръчката
                                    if ($best_fit_order_total === null || floatval($csv_order_total) < $best_fit_order_total) {
                                        $best_fit_order_total = floatval($csv_order_total);
                                        $best_fit_price = floatval($csv_price);
                                    }
                                }
                            }
                            fclose($handle);

                        
if ($best_fit_price !== null) {
    $delivery_price = number_format($best_fit_price, 2) . ' лв.';
    if ($is_cod_payment) {
        $arr_data['service']['additionalServices']['cod']['amount'] = round($amount + $best_fit_price, 2);
    }

    if (isset($arr_data['service']['additionalServices']['cod']['includeShippingPrice'])) {
        unset($arr_data['service']['additionalServices']['cod']['includeShippingPrice']);
    }

    if ( method_exists($order, 'get_payment_method') && $order->get_payment_method() !== 'cod' ) {
        $arr_data['payment'] = ['courierServicePayer' => 'SENDER'];
        $arr_data['service']['additionalServices']['cod']['amount'] = 0;
        if (isset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])) {
            unset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems']);
        }
    }
} else {
    $delivery_price = 'Няма намерена цена в CSV';
}
                        } else {
                            $delivery_price = 'CSV файлът липсва';
                        }
                    } else {
                        $delivery_price = 'Не е качен CSV файл';
                    }

            }
            else {
                if ( method_exists($order, 'get_payment_method') && $order->get_payment_method() !== 'cod' ) {
                    $arr_data['payment'] = ['courierServicePayer' => 'SENDER'];
                    if (!isset($arr_data['service']['additionalServices']['cod'])) {
                        $arr_data['service']['additionalServices']['cod'] = [];
                    }
                    $arr_data['service']['additionalServices']['cod']['amount'] = 0;
                    if (isset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])) {
                        unset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems']);
                    }
                }

           
$products_only_with_vat = 0.0;
if (isset($order) && is_object($order) && method_exists($order, 'get_items')) {
    foreach ($order->get_items('line_item') as $item) {
        $products_only_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
    }
}

if (!isset($arr_data['service'])) {
    $arr_data['service'] = [];
}
if (!isset($arr_data['service']['additionalServices'])) {
    $arr_data['service']['additionalServices'] = [];
}
if (!isset($arr_data['service']['additionalServices']['cod'])) {
    $arr_data['service']['additionalServices']['cod'] = [];
}

$arr_data['service']['additionalServices']['cod']['amount'] = round(
    $products_only_with_vat,
    2
);

if (empty($arr_data['service']['additionalServices']['cod']['processingType'])) {
    $arr_data['service']['additionalServices']['cod']['processingType'] = 'CASH';
}

if (
    isset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
    && is_array($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
    && !empty($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
) {
    $sum_with_vat = 0.0;
    foreach ($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'] as $it) {
        if (is_array($it) && isset($it['amountWithVat'])) {
            $sum_with_vat += (float) $it['amountWithVat'];
        }
    }

    $arr_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);
}

                if (empty($arr_data['service']['additionalServices']['cod']['processingType'])) {
                    $arr_data['service']['additionalServices']['cod']['processingType'] = 'CASH';
                }

                // FIX: при COD НЕ включваме доставката в cod.amount.
                // Ако има fiscalReceiptItems, cod.amount трябва да е сборът на amountWithVat (само продуктите).
                if (
                        isset($arr_data['service']['additionalServices']['cod'])
                        && is_array($arr_data['service']['additionalServices']['cod'])
                ) {
                    if (
                            isset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                            && is_array($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                            && !empty($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])
                    ) {
                        $sum_with_vat = 0.0;
                        foreach ($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'] as $it) {
                            if (is_array($it) && isset($it['amountWithVat'])) {
                                $sum_with_vat += (float) $it['amountWithVat'];
                            }
                        }
                        $arr_data['service']['additionalServices']['cod']['amount'] = round($sum_with_vat, 2);
                    } else {
                        // fallback: ако няма фискални редове, ползваме total - shipping (само продукти)
                        if (function_exists('WC') && WC()->cart) {
                            $totals = WC()->cart->get_totals();
                            $products_only = (float) ($totals['total'] ?? 0) - (float) ($totals['shipping_total'] ?? 0);
                            $arr_data['service']['additionalServices']['cod']['amount'] = round(max(0, $products_only), 2);
                        }
                    }
                }

                // NEW: foreign fix - amount да е само продуктите (без shipping/fees) + postCode от billing_address_2
                $countryId = isset($arr_data['recipient']['addressLocation']['countryId'])
                        ? (int) $arr_data['recipient']['addressLocation']['countryId']
                        : 0;

                if ($countryId > 0 && $countryId !== 100) {

                    // 1) postCode (ПОЩЕНСКИ КОД) за чужбина
                    $postCode = '';
                    if (isset($order) && is_object($order) && method_exists($order, 'get_billing_address_2')) {
                        $postCode = (string) $order->get_billing_address_2();
                    }
                    $postCode = trim($postCode);

                    if ($postCode !== '') {
                        $arr_data['recipient']['addressLocation']['postCode'] = $postCode;
                    }

                    // 2) cod.amount = сбор (line_total + line_tax) само от продуктите
                    $products_only_with_vat = 0.0;
                    if (isset($order) && is_object($order) && method_exists($order, 'get_items')) {
                        foreach ($order->get_items('line_item') as $item) {
                            if (!is_object($item) || !method_exists($item, 'get_total')) continue;
                            $products_only_with_vat += (float) $item->get_total() + (float) $item->get_total_tax();
                        }
                    }

                    if (!isset($arr_data['service']['additionalServices'])) {
                        $arr_data['service']['additionalServices'] = [];
                    }
                    if (!isset($arr_data['service']['additionalServices']['cod'])) {
                        $arr_data['service']['additionalServices']['cod'] = [];
                    }

                    // ако има COD блок – форсираме amount да е продуктите
                   $arr_data['service']['additionalServices']['cod']['amount'] = round($products_only_with_vat, 2);
                }

        
            // За чужбина не изпращаме OBPD към calculate
            $is_foreign_calc = false;

            // 1) Проверка по billing country (най-сигурно ако е попълнено в поръчката)
            $billing_iso2 = '';
            if ( isset($order) && is_object($order) && method_exists($order, 'get_billing_country') ) {
                $billing_iso2 = strtoupper( trim( (string) $order->get_billing_country() ) );
            }
            if ( $billing_iso2 !== '' && $billing_iso2 !== 'BG' ) {
                $is_foreign_calc = true;
            }

            // 2) Проверка по speedy country meta
            if ( ! $is_foreign_calc && isset($order) && is_object($order) && method_exists($order, 'get_meta') ) {
                $recipient_country_id = (int) $order->get_meta('_shipping_shipping_country');
                if ( $recipient_country_id > 0 && $recipient_country_id !== 100 ) {
                    $is_foreign_calc = true;
                }
            }

            // 3) fallback по payload countryId
            if ( ! $is_foreign_calc && isset($arr_data['recipient']['addressLocation']['countryId']) ) {
                $recipient_country_id = (int) $arr_data['recipient']['addressLocation']['countryId'];
                if ( $recipient_country_id > 0 && $recipient_country_id !== 100 ) {
                    $is_foreign_calc = true;
                }
            }

            // 4) офис fallback: ако pickupOfficeId не е BG офис в локалната база -> приемаме чужбина
            if (
                ! $is_foreign_calc
                && isset($arr_data['recipient']['pickupOfficeId'])
                && (int) $arr_data['recipient']['pickupOfficeId'] > 0
            ) {
                $pickup_office_id = (int) $arr_data['recipient']['pickupOfficeId'];
                $bg_office = Speedy_DB::get_office_by_id($pickup_office_id);
                if ( ! $bg_office ) {
                    $is_foreign_calc = true;
                }
            }

$is_cod_payment = method_exists($order, 'get_payment_method')
    && $order->get_payment_method() === 'cod';

if (!$is_cod_payment) {
    // При банков превод/карта: не изпращаме COD секция към Speedy calculate
    if (isset($arr_data['service']['additionalServices'])) {
        unset($arr_data['service']['additionalServices']);
    }

    // Обичайно при предплатени поръчки подателят поема куриерската услуга
    $arr_data['payment']['courierServicePayer'] = 'SENDER';
}

// FINAL normalize за foreign calculate:
// - платец винаги SENDER
// - без fiscalReceiptItems
// - without forced OBPD removal here; destination-services allowance already decided it
$is_foreign_calc_final = false;

$billing_iso2_final = '';
if (isset($order) && is_object($order) && method_exists($order, 'get_billing_country')) {
    $billing_iso2_final = strtoupper(trim((string) $order->get_billing_country()));
}

$speedy_country_id_final = 0;
if (isset($order) && is_object($order) && method_exists($order, 'get_meta')) {
    $speedy_country_id_final = (int) $order->get_meta('_shipping_shipping_country');
}

if (
    ($billing_iso2_final !== '' && $billing_iso2_final !== 'BG')
    || ($speedy_country_id_final > 0 && $speedy_country_id_final !== 100)
) {
    $is_foreign_calc_final = true;
} elseif (
    isset($arr_data['recipient']['addressLocation']['countryId'])
    && (int) $arr_data['recipient']['addressLocation']['countryId'] > 0
    && (int) $arr_data['recipient']['addressLocation']['countryId'] !== 100
) {
    $is_foreign_calc_final = true;
} elseif (
    isset($arr_data['recipient']['pickupOfficeId'])
    && (int) $arr_data['recipient']['pickupOfficeId'] > 0
) {
    $pickup_office_id_final = (int) $arr_data['recipient']['pickupOfficeId'];
    $bg_office_final = Speedy_DB::get_office_by_id($pickup_office_id_final);
    if (!$bg_office_final) {
        $is_foreign_calc_final = true;
    }
}

if ($is_foreign_calc_final) {
    if (!isset($arr_data['payment']) || !is_array($arr_data['payment'])) {
        $arr_data['payment'] = [];
    }
    $arr_data['payment']['courierServicePayer'] = 'SENDER';

    if (isset($arr_data['payment']['declaredValuePayer'])) {
        unset($arr_data['payment']['declaredValuePayer']);
    }

    if (isset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems'])) {
        unset($arr_data['service']['additionalServices']['cod']['fiscalReceiptItems']);
    }
}

// --- Конвертиране на COD към валутата на дестинацията (напр. RON) ---
if (
    isset($arr_data['service']['additionalServices']['cod']['amount']) &&
    $is_foreign_order
) {
    // Определи валутата на дестинацията
    $country_currency_map = [
        'RO' => 'RON',
        // добави и други държави при нужда
    ];
    $target_currency = '';
    if (isset($country_currency_map[$billing_country_iso2])) {
        $target_currency = $country_currency_map[$billing_country_iso2];
    }
    if ($target_currency) {
        $currency_rates = speedy_get_setting('currency_rate', []);
        $rate = 0;
        foreach ($currency_rates as $item) {
            if (isset($item['iso_code']) && strtoupper($item['iso_code']) === $target_currency) {
                $rate = (float)$item['rate'];
                break;
            }
        }
        if ($rate > 0) {
            $arr_data['service']['additionalServices']['cod']['amount'] =
                round($arr_data['service']['additionalServices']['cod']['amount'] * $rate, 2);
        }
    }
}

// Guard: ако имаме siteId за addressLocation, countryId не трябва да липсва.
if (
    isset($arr_data['recipient']['addressLocation'])
    && is_array($arr_data['recipient']['addressLocation'])
    && !empty($arr_data['recipient']['addressLocation']['siteId'])
    && empty($arr_data['recipient']['addressLocation']['countryId'])
) {
    $resolved_country_id = (int) $order->get_meta('_shipping_shipping_country');

    if ($resolved_country_id <= 0 && method_exists($order, 'get_billing_country')) {
        $billing_iso2_guard = strtoupper(trim((string) $order->get_billing_country()));

        if ($resolved_country_id <= 0 && $billing_iso2_guard !== '' && function_exists('speedy_country_info_fallback_by_iso')) {
            $country_info_guard = speedy_country_info_fallback_by_iso($billing_iso2_guard);
            if (is_array($country_info_guard) && !empty($country_info_guard['id'])) {
                $resolved_country_id = (int) $country_info_guard['id'];
            }
        }

        if ($resolved_country_id <= 0) {
            $resolved_country_id = ($billing_iso2_guard !== '' && $billing_iso2_guard !== 'BG') ? 300 : 100;
        }
    }

    if ($resolved_country_id > 0) {
        $arr_data['recipient']['addressLocation']['countryId'] = $resolved_country_id;
    }
}

                $response = WS_Speedy_Request::call(SPEEDY_API_BASE_URL . 'calculate', $arr_data);

                // FIX: взимаме първата успешна калкулация (не винаги е [0])
                $selected_calc = null;
                if (is_array($response) && !empty($response['calculations']) && is_array($response['calculations'])) {
                    foreach ($response['calculations'] as $calc) {
                        if (is_array($calc) && empty($calc['error']) && isset($calc['price']['total'])) {
                            $selected_calc = $calc;
                            break;
                        }
                    }
                }

                if ($selected_calc && isset($selected_calc['price']['total'])) {

                    $delivery_price = (float) $selected_calc['price']['total'];
                    $deliverycurrency = isset($selected_calc['price']['currency']) ? (string) $selected_calc['price']['currency'] : '';

                    if (speedy_get_setting('cenadostavka') == 'nadbavka') {
                        $delivery_price += (float) speedy_get_setting('suma_nadbavka');
                    }

                    $delivery_price = number_format($delivery_price, 2, '.', '') . ' ' . $deliverycurrency;
                } else {
                    $delivery_price = 'Грешка при изчисляване';
                }
            }

            }
        }


        
    
if ($waybill === NULL) {
echo '
<button class="button" id="calculateButton">Изчисляване цена на доставка</button>

<script>
document.addEventListener("DOMContentLoaded", function () {
    document.getElementById("calculateButton").addEventListener("click", function (e) {
        e.preventDefault();

        const weight = document.getElementById("weight").value;
        const clientid = document.getElementById("obekt").value;
        const doofisaddress = document.getElementById("doofisaddress").value;
        const drop = document.getElementById("drop").value;

        const complexId = document.getElementById("complex_id_hidden")?.value || "";
        const streetId = document.getElementById("street_id_hidden")?.value || "";
        const complexName = document.getElementById("complexId").value;
        const streetName = document.getElementById("streetId").value;
        const streetNo = document.getElementById("streetNo").value;
        const blockNo = document.getElementById("blockNo").value;
        const entranceNo = document.getElementById("entranceNo").value;
        const floorNo = document.getElementById("floorNo").value;
        const apartmentNo = document.getElementById("apartmentNo").value;
        const town = document.getElementById("town").value;

        // NEW: четем ръчните размери от таблицата
        const widths = Array.from(document.getElementsByName("width[]")).map(i => encodeURIComponent(i.value || ""));
        const lengths = Array.from(document.getElementsByName("length[]")).map(i => encodeURIComponent(i.value || ""));
        const heights = Array.from(document.getElementsByName("height[]")).map(i => encodeURIComponent(i.value || ""));
        const weights = Array.from(document.getElementsByName("weight[]")).map(i => encodeURIComponent(i.value || ""));

        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set("weight", weight);
        currentUrl.searchParams.set("clientid", clientid);
        currentUrl.searchParams.set("doofisaddress", doofisaddress);
        currentUrl.searchParams.set("drop", drop);
        currentUrl.searchParams.set("complexId", complexId);
        currentUrl.searchParams.set("streetId", streetId);
        currentUrl.searchParams.set("streetNo", streetNo);
        currentUrl.searchParams.set("blockNo", blockNo);
        currentUrl.searchParams.set("entranceNo", entranceNo);
        currentUrl.searchParams.set("floorNo", floorNo);
        currentUrl.searchParams.set("apartmentNo", apartmentNo);
        currentUrl.searchParams.set("town", town);

        // NEW: пращаме размерите и при калкулация
        currentUrl.searchParams.set("widths", widths.join(","));
        currentUrl.searchParams.set("lengths", lengths.join(","));
        currentUrl.searchParams.set("heights", heights.join(","));
        currentUrl.searchParams.set("weights", weights.join(","));

        currentUrl.searchParams.set("complexId", complexId);
        currentUrl.searchParams.set("streetId", streetId);
        currentUrl.searchParams.set("complexName", complexName);
        currentUrl.searchParams.set("streetName", streetName);

        window.location.href = currentUrl.toString();
    });
});
</script>
';



        //$shown = isset($delivery_price)
          //  ? (is_numeric($delivery_price)
            //    ? htmlspecialchars($delivery_price) . ' лв.'
              //  : htmlspecialchars($delivery_price))
            //: '-';

       //$bg_price = $delivery_price; // например 43.57
        //$exchange_rate = 1.9548;

        //$eur_price = $bg_price / $exchange_rate;

        // Форматиране на двете стойности с 2 десетични знака
        //$shown = number_format($bg_price, 2, '.', '') . ' лв. / ' . number_format($eur_price, 2, '.', '') . ' €';
        // $shown = number_format($bg_price, 2, '.', '') . ' лв. ';

   
        $rate = 1.95583; 

        // Ако $delivery_price съдържа "лв." или други символи, това ще го почисти и конвертира правилно:
        $price = (float) filter_var($delivery_price, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $price_formatted = number_format($price, 2, '.', '');

        $today = new DateTime();

        // Дата за смяна на валутата
        $changeDate = new DateTime("2026-01-01");

        // Проверка
        if ($today >= $changeDate) {
            $deliverycurrency = "EUR";
        } else {
            $deliverycurrency = "BGN"; // или каквото е в момента
        }

        echo '
        <p>Цена за доставка:
            <span id="result">' . $price_formatted . '</span> ' . $deliverycurrency . '
            <span id="result_eur" style="display:none!important;"></span>
        </p>
        <p style="color:red;">В случай, че за "Образуване на цена за доставка" използвате опция "Фиксирана цена за доставка", "Безплатна доставка" или "Собствени цени", след 01.01.2026, трябва да актуализирате сумите за доставка в Евро в настройките на модула.</p>

        <script>
            const rate = ' . $rate . ';

            function convertBGNtoEUR(fromId, toId) {
                const fromEl = document.getElementById(fromId);
                const toEl = document.getElementById(toId);
                if (!fromEl || !toEl) return;

                // Поддържа както input.value, така и span/textContent
                let raw = (fromEl.value !== undefined) ? fromEl.value : fromEl.textContent || "";
                // Премахваме всичко освен цифри, точка, запетая и минус
                raw = String(raw).replace(/[^0-9,.-]/g, "").trim();
                if (!raw) { toEl.textContent = ""; return; }

                // Ако има запетая -> правим точка
                raw = raw.replace(",", ".");
                const val = parseFloat(raw);

                if (!isNaN(val)) {
                    const eur = (val / rate).toFixed(2);
                    toEl.textContent = " / " + eur + " €";
                } else {
                    toEl.textContent = "";
                }
            }

            document.addEventListener("DOMContentLoaded", function() {
                convertBGNtoEUR("result", "result_eur");
            });
        </script>
        ';





        echo '<a class="button generate-items" id="generate-waybill" href="#">Генериране на товарителница</a>';

        echo '

    <style>
        #order_custom{
            display:none!important;
        }
    </style>

    <script>
    document.getElementById("generate-waybill").addEventListener("click", function(e) {
        e.preventDefault();
        
        const orderId = "' . esc_js($order_id) . '";
        const contents = encodeURIComponent(document.getElementById("contents").value);
        const obekt = encodeURIComponent(document.querySelector("select[name=\'obekt\']").value);
        const opakovka = encodeURIComponent(document.getElementById("opakovka").value);
        const weight = encodeURIComponent(document.getElementById("weight").value);
        const doofisaddress = encodeURIComponent(document.getElementById("doofisaddress").value);
        const drop = encodeURIComponent(document.getElementById("drop").value);

        const complexId = encodeURIComponent(document.getElementById("complex_id_hidden")?.value || "");
const streetId = encodeURIComponent(document.getElementById("street_id_hidden")?.value || "");
const complexName = encodeURIComponent(document.getElementById("complexId").value || "");
const streetName = encodeURIComponent(document.getElementById("streetId").value || "");
        const streetNo = encodeURIComponent(document.getElementById("streetNo").value);
        const blockNo = encodeURIComponent(document.getElementById("blockNo").value);
        const apartmentNo = encodeURIComponent(document.getElementById("apartmentNo").value);
        const floorNo = encodeURIComponent(document.getElementById("floorNo").value);
        const entranceNo = encodeURIComponent(document.getElementById("entranceNo").value);
        const town = encodeURIComponent(document.getElementById("town").value);

        const widths = Array.from(document.getElementsByName("width[]")).map(i => encodeURIComponent(i.value));
        const lengths = Array.from(document.getElementsByName("length[]")).map(i => encodeURIComponent(i.value));
        const heights = Array.from(document.getElementsByName("height[]")).map(i => encodeURIComponent(i.value));
        const weights = Array.from(document.getElementsByName("weight[]")).map(i => encodeURIComponent(i.value));

        const widthsStr = widths.join(",");
        const lengthsStr = lengths.join(",");
        const heightsStr = heights.join(",");
        const weightsStr = weights.join(",");


        const url = "' . get_site_url() . '?wc-api=shipping_woocommerce_speedy_shipping_print_waybill_cb" +
                    "&orderid=" + orderId +
                    "&contents=" + contents +
                    "&obekt=" + obekt +
                    "&opakovka=" + opakovka +
                    "&weight=" + weight +
                    "&doofisaddress=" + doofisaddress +
                   "&complexId=" + complexId +
                    "&streetId=" + streetId +
                    "&complexName=" + complexName +
                    "&streetName=" + streetName +
                    "&streetNo=" + streetNo +
                    "&apartmentNo=" + apartmentNo +
                    "&floorNo=" + floorNo +
                    "&entranceNo=" + entranceNo +
                    "&town=" + town +
                    "&blockNo=" + blockNo +
                    "&drop=" + drop +
                    "&widths=" + widthsStr +
                    "&lengths=" + lengthsStr +
                    "&heights=" + heightsStr +
                    "&weights=" + weightsStr;

        window.location.href = url;
    });
    </script>';
}

        

        return;
    }

    function generate_speedy_waybill_button_html( $order ){
        if( !$order->has_shipping_method('speedy_shipping') ) return;

        $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;

        $waybill = json_decode($order->get_meta('shipping_speedy_waybill', true), true);

        if($waybill != NULL && WS_Speedy_Request::is_api_error($waybill)) {
            echo '<div style="margin: 10px 0; padding: 5px; border: 2px solid #ff0000; clear: both; text-align: left;">Грешка: ' . WS_Speedy_Request::is_api_error($waybill) . '</div>';
        }

        if($waybill != NULL && is_array($waybill)) {
            if(array_key_exists('id', $waybill)) {
                echo '<a class="button generate-items" target="_blank" href="' . get_site_url() . '?wc-api=shipping_woocommerce_speedy_shipping_print_waybill_cb&speedy_waybill=' . $waybill['id'] . '">Печат на Speedy Товарителница</a>';
            }
        }

        return;
    }

    /**
     * Register the shipping method hooks.
     *
     * @return void
     */
    public function registerHooks() {
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options'] );
        add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'after_process_admin_options'] );

        add_action( 'woocommerce_order_item_add_action_buttons', [$this, 'generate_speedy_tovaritelnica'], 10, 1);

         add_action( 'woocommerce_order_item_add_action_buttons', [$this, 'generate_speedy_waybill_button_html'], 10, 1);
    }


    /**
     * Action Hooks
     */
    public function after_process_admin_options() {
        $is_valid = Speedy_API::verify_account();

        $this->update_option( 'logged_in', $is_valid );

        if( $is_valid && ! empty( $_POST['woocommerce_speedy_shipping_sender_city'] ) ) {
            $city_name_raw = $_POST['woocommerce_speedy_shipping_sender_city'];
            $city_name = trim( explode( '(', $city_name_raw )[0] );
            $city_id   = Speedy_DB::get_city_id( $city_name );

            $this->update_option( 'city_id', $city_id );
        }

        if ( isset( $_POST['woocommerce_speedy_shipping_method_currency_rate'] ) ) {
            $raw_rows = wp_unslash( $_POST['woocommerce_speedy_shipping_method_currency_rate'] );
            $clean = [];

            if ( is_array( $raw_rows ) ) {
          
foreach ( $raw_rows as $row ) {
    $iso = isset( $row['iso_code'] ) ? strtoupper( sanitize_text_field( (string) $row['iso_code'] ) ) : '';
    $iso = preg_replace( '/[^A-Z]/', '', $iso );

    $rate_raw = isset( $row['rate'] ) ? (string) $row['rate'] : '';
    $rate_raw = str_replace( ',', '.', $rate_raw );
    $rate = (float) $rate_raw;

    // Валиден ISO (точно 3 букви) и валиден положителен курс
    if ( strlen( $iso ) !== 3 || $rate <= 0 ) {
        continue;
    }

   $clean[] = [
    'iso_code' => $iso,
    'rate'     => $rate,
];
}
            }

            // Записваме асоциативно: ['RON' => 4.95, 'HUF' => 390, ...]
            $this->update_option( 'currency_rate', $clean );
        }

        if ( isset( $_POST['woocommerce_speedy_shipping_status_update_mappings'] ) ) {
            $raw_mappings = wp_unslash( $_POST['woocommerce_speedy_shipping_status_update_mappings'] );
            $allowed_statuses = wc_get_order_statuses();
            $clean_mappings = [];

            if ( is_array( $raw_mappings ) ) {
                foreach ( speedy_get_final_tracking_statuses() as $code => $status_data ) {
                    $selected_status = isset( $raw_mappings[ $code ] ) ? sanitize_text_field( (string) $raw_mappings[ $code ] ) : '';

                    if ( $selected_status !== '' && isset( $allowed_statuses[ $selected_status ] ) ) {
                        $clean_mappings[ $code ] = $selected_status;
                    }
                }
            }

            $this->update_option( 'status_update_mappings', $clean_mappings );
        }

    }

    

    public static function regions() {
        return array( 'Благоевград', 'Бургас', 'Варна', 'Велико Търново', 'Видин', 'Враца', 'Габрово', 'Добрич', 'Кърджали', 'Кюстендил', 'Ловеч', 'Монтана', 'Пазарджик', 'Перник', 'Плевен', 'Пловдив', 'Разград', 'Русе', 'Силистра', 'Сливен', 'Смолян', 'София Област', 'София', 'Стара Загора', 'Търговище', 'Хасково', 'Шумен', 'Ямбол' );
    }
}