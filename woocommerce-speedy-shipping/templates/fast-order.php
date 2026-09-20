<?php
    $methods = apply_filters( 'custom_shipping_methods', [] );
?>
<div class="fast-order-wrapper">
    <div class="fast-order-loader">
        <div class="loader"></div>
    </div>
    <div class="order-form">
        <div class="error-box">
            Съобщение за грешка
        </div>
        <form name="fast-order-form" id="fast-order-form" method="POST" autocomplete="off" action="<?php echo admin_url( 'admin-ajax.php' ) ?>">
            <input type="hidden" name="action" value="fast_order">
            <input type="hidden" name="inner_action">
            <input type="hidden" name="city_id" id="city_id">
            <input type="hidden" name="product" value="<?php echo get_the_ID() ?>">
            <input type="hidden" name="prod_var_id">
            <input type="text" class="form-control" name="first_last_name" placeholder="Име и фамилия" required />
            <input type="text" class="form-control" name="email" placeholder="Имейл" required />
            <input type="text" class="form-control" name="phone" placeholder="Телефон" required />

            <?php if( count( $methods ) > 1 ) : ?>
                <div class="shipping-method">
                    <label>
                        Куриер
                        <div class="select-wrapper">
                            <select name="shipping-method" id="shipping-method">
                                <?php foreach( $methods as $key => $label ) : ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>.
                            </select>
                        </div>
                    </label>
                </div>
            <?php else: ?>
                <input type="hidden" name="shipping-method" id="shipping-method" value="<?= array_keys( $methods )[0] ?>">
            <?php endif; ?>

            <div class="shipping-type">
                <label>
                    Доставка до
                    <div class="select-wrapper">
                        <select name="shipping-type" id="shipping-type">
                            <option value="office">Офис</option>
                            <option value="address">Адрес</option>
                        </select>
                    </div>
                </label>
            </div>

            <div class="region">
                <label>
                    Област
                    <div class="select-wrapper">
                        <select name="region" id="region">
                                <option selected disabled>Изберете област</option>
                            <?php foreach( WC()->countries->get_states( 'BG' ) as $key => $value ) : ?>
                                <option value="<?= $key ?>"><?= $value ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </label>
            </div>

            <div class="city-autocomplete">
                <label>
                    Населено място
                    <div class="select-wrapper">
                        <select name="city" id="city">
                            <option selected disabled>Изберете град</option>
                        </select>
                    </div>
                </label>
            </div>

            <div class="office-wrapper">
                <label>
                    Офис
                    <div class="select-wrapper">
                        <select name="office" id="office">
                        </select>
                    </div>
                </label>
            </div>

            <div class="street-wrapper">
                <input type="text" name="address" class="form-control" placeholder="Адрес">
            </div>

            <div class="notes">
                <label>
                    Бележки
                    <textarea name="notes" class="form-control" placeholder="Бележки"></textarea>
                </label>
            </div>

            <div class="quantity">
                <label for="prod-quantity">
                    Количество
                </label>
                <input type="number" name="quantity" min="1" value="1">
            </div>

            <label class="terms">
                <input type="checkbox" name="terms" value="1" required>
                Съгласен съм с <a href="<?= get_permalink( wc_terms_and_conditions_page_id() ) ?>" target="_blank">Общите условия</a> на сайта
            </label>

            <div class="calculate-shipping">
                <p style="text-align: center;">Моля изчислете цена на доставка, за да продължите.</p>
                <button class="calculate-shipping" name="calculate" value="calculate">
                    Изчисли цена на доставка
                </button>
                <div class="order-price">
                    <span class="order-price-label">
                        Цена на поръчка:
                    </span>
                    <span class="order-price-value">
                    </span>
                </div>
                <div class="shipping-price">
                    <span class="shipping-price-label">
                        Цена на доставка:
                    </span>
                    <span class="shipping-price-value">
                    </span>
                </div>
            </div>

            <input type="submit" id="do-order" name="submit" value="Изпрати поръчка" class="single_add_to_cart_button button alt">
        </form> 
    </div>
</div>