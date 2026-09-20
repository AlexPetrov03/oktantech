var speedyCheckoutI18nSettings =
    window.shipping_settings && shipping_settings.i18n
        ? shipping_settings.i18n
        : {};
var mapButtonLabel = speedyCheckoutI18nSettings.map_button_label || 'Избери офис/автомат от карта';
var selectOfficeButtonCaption = speedyCheckoutI18nSettings.select_button_caption || 'Изберете';
var cityPlaceholderLabel = speedyCheckoutI18nSettings.city_placeholder || 'Изберете населено място';
var officePlaceholderLabel = speedyCheckoutI18nSettings.office_placeholder || 'Изберете офис';
var automatPlaceholderLabel = speedyCheckoutI18nSettings.automat_placeholder || 'Изберете Автомат';
var office2NoTestLabel = speedyCheckoutI18nSettings.office2_no_test || 'Автомат (без опция за ТЕСТ)';
var officeLabel = speedyCheckoutI18nSettings.office_label || 'Офис';
var automatLabel = speedyCheckoutI18nSettings.automat_label || 'Автомат';
var requiredTitleLabel = speedyCheckoutI18nSettings.required_title || 'задължително';
var addressNotePlaceholderLabel = speedyCheckoutI18nSettings.address_note_placeholder || 'Забележка към адреса:';
var postcodeLabel = speedyCheckoutI18nSettings.postcode_label || 'Пощенски код';

function normalizeCountryIdValue(v) {
    if (v && typeof v === 'object' && v.countryId) return parseInt(v.countryId, 10) || 0;
    return parseInt(v, 10) || 0;
}

document.addEventListener("DOMContentLoaded", function() {

    function googleMapFocus(siteName) {
        let office_locator = jQuery('#frameOfficeLocator');
        let url = new URL(office_locator.attr('src'));
        let search_params = url.searchParams;

        search_params.set('siteName', siteName);
        url.search = search_params.toString();

        office_locator.attr('src', url.toString())
    }

    if (!document.getElementById('speedy-address-type-2-force-style')) {
        var style = document.createElement('style');
        style.id = 'speedy-address-type-2-force-style';
        style.textContent = `
            body.speedy-address-type-2.speedy-shipping-address #billing_street_field {
                display: block !important;
            }

            body.speedy-address-type-2 #billing_street_field {
                width: 100% !important;
            }

            #billing_street_field .speedy-street-error {
                color: #e2401c;
                display: block;
                font-size: 0.875em;
                margin-top: 0.5em;
            }
        `;
        document.head.appendChild(style);
    }

var postCodeTimeout;

    jQuery(document.body).ready(function($) {

        function speedyApplyAddressTypeBodyClasses() {
            var addressType = parseInt(window.speedy_address_type, 10) || 1;
            var shippingType = $('#billing_shipping_type').val();

            $('body').toggleClass('speedy-address-type-2', addressType === 2);
            $('body').toggleClass('speedy-shipping-address', shippingType === 'address');
        }

        // NEW: извикваме го при load + при смяна + при Woo updated_checkout
        speedyApplyAddressTypeBodyClasses();
        $(document.body).on('change', '#billing_shipping_type', speedyApplyAddressTypeBodyClasses);
        $(document.body).on('updated_checkout', speedyApplyAddressTypeBodyClasses);


        jQuery('#billing_state').on('change', function(e) {
            var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
            if (iso2 !== 'BG' && iso2 !== '') return;

            let siteName = jQuery(this).find(":selected").text()
            siteName = siteName.replace('гр. ','').replace('с. ','');
            googleMapFocus(siteName)
        })

    })




    // Dynamically create the button
    const buttonHTML = `<a class="btn-primary" href="#" id="openModalBtn">${mapButtonLabel}</a>`;

    jQuery( "#billing_address_2_field" ).append(buttonHTML );

    const cityName = jQuery('#billing_state').find(":selected").text();

    const officeLocatorLang =
        window.shipping_settings && shipping_settings.office_locator_lang
            ? String(shipping_settings.office_locator_lang).toLowerCase()
            : 'bg';

    // Dynamically create the modal
    const modalHTML = `
     <style>
        /* Custom modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0); 
            background-color: rgba(0,0,0,0.4); /* Black with opacity */
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 900px;
        }

        .modal-header, .modal-footer {
            padding: 10px;
            text-align: center;
        }

        .modal-body {
            padding: 20px;
            text-align: center;
        }

        /* Close button */
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* Button styles */
        .btn-primary {
               background-color: #007bff;
              color: white;
              padding: 10px 20px;
              border: none;
              cursor: pointer;
              display: block;
              border-radius: 10px;
              margin: 7px;
              text-align: center;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }
        #billing_address_1_field{
          display:none!important;
        }
    </style>
        <div id="exampleModal" class="modal">
            <div class="modal-content">
                <div class="modal-body">
                    <iframe id="frameOfficeLocator" allow="geolocation" name="frameOfficeLocator"
                            src="https://services.speedy.bg/office_locator_widget_v3/office_locator.php?lang=${officeLocatorLang}&amp;showAddressForm=0&amp;showOfficesList=0&amp;&amp;&amp;&amp;selectOfficeButtonCaption=${encodeURIComponent(selectOfficeButtonCaption)}&amp;siteName=${cityName}" 
                            width="100%" height="600px" style="border:0;">
                    </iframe>
                </div>
            </div>
        </div>
    `;
    
    // Append the modal to the body
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // Get the modal and close button
    const modal = document.getElementById("exampleModal");
    function getCurrentCityForLocator() {
    var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
        if (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO') {
        return (jQuery('#billing_city_text').val() || '').trim();
    }
    return (jQuery('#billing_state option:selected').text() || '')
        .replace('гр. ', '')
        .replace('с. ', '')
        .trim();
}

function updateOfficeLocatorIframe(countryId) {
    var $iframe = jQuery('#frameOfficeLocator');
    if (!$iframe.length) return;

    var rawSrc = $iframe.attr('src') || '';
    var url = new URL(rawSrc);
    var params = url.searchParams;

    var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
    var shippingType = (jQuery('#billing_shipping_type').val() || '');
    var postcode = (jQuery('#billing_address_2').val() || '').trim();
    var cityName = getCurrentCityForLocator();

    if (cityName) params.set('siteName', cityName);

    if (isForeign && shippingType === 'office') {
        if (countryId > 0) params.set('countryId', String(countryId));
        if (postcode !== '') params.set('postCode', postcode);
    } else {
        params.delete('postCode');
    }

    url.search = params.toString();
    $iframe.attr('src', url.toString());
}
    const closeButtons = document.querySelectorAll(".close");

    // Open the modal when the button is clicked
    document.getElementById("openModalBtn").onclick = function(e) {
    e.preventDefault();

    var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
    var hiddenCountryId = parseInt(jQuery('#speedy_country_id').val() || '0', 10) || 0;

    if (!isForeign) {
        updateOfficeLocatorIframe(100);
        modal.style.display = "block";
        return;
    }

    if (hiddenCountryId > 0) {
        updateOfficeLocatorIframe(hiddenCountryId);
        modal.style.display = "block";
        return;
    }

    if (typeof window.speedyGetCountryId === 'function') {
        window.speedyGetCountryId(iso2, function(cidRaw) {
            var cid = normalizeCountryIdValue(cidRaw);
            if (cid > 0) {
                if (!jQuery('#speedy_country_id').length) {
                    jQuery('<input>', { type: 'hidden', id: 'speedy_country_id', name: 'speedy_country_id', value: String(cid) }).appendTo('form.checkout');
                } else {
                    jQuery('#speedy_country_id').val(String(cid));
                }
            }
            updateOfficeLocatorIframe(cid);
            modal.style.display = "block";
        });
        return;
    }

    updateOfficeLocatorIframe(0);
    modal.style.display = "block";
};

    // Close the modal when the user clicks on <span> (x) or close button
    closeButtons.forEach(function(closeButton) {
        closeButton.onclick = function() {
            modal.style.display = "none";
        };
    });

    // Close the modal when the user clicks outside of the modal content
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    };

   function getOfficeAddressText(officeObj) {
  if (!officeObj) return '';
  var a = officeObj.address;

  if (typeof a === 'string') return a;

  if (a && typeof a === 'object') {
    return a.fullAddressString || a.fullAddress || a.addressString || '';
  }

  return '';
}

function getOfficeSiteName(officeObj) {
  if (!officeObj) return '';
  if (officeObj.siteName) return officeObj.siteName;

  var a = officeObj.address;
  if (a && typeof a === 'object') {
    return a.siteName || a.localityCyrName || a.localityName || '';
  }

  return '';
}

function markCityFieldAsFilled(cityText) {
var $cityText = jQuery('#billing_city_text');
var $cityField = jQuery('#billing_city_field');
var $cityList = jQuery('#billing-city-suggestions');

if ($cityText.length) {
$cityText.val(cityText || '');
$cityText.trigger('change');
$cityText.removeAttr('aria-invalid');
}

if ($cityList.length) {
$cityList.empty().hide();
}

if ($cityField.length) {
$cityField.removeClass('woocommerce-invalid').addClass('woocommerce-validated');
}
}

window.addEventListener('message', function (e) {
  var returnedOfficeJsonObject = e.data;
  modal.style.display = "none";

  if (!returnedOfficeJsonObject || typeof returnedOfficeJsonObject !== 'object') {
    return;
  }

  // Някои интеграции връщат payload-а вложен
  if (returnedOfficeJsonObject.office && typeof returnedOfficeJsonObject.office === 'object') {
    returnedOfficeJsonObject = returnedOfficeJsonObject.office;
  }

  var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');

  if (returnedOfficeJsonObject.type == 'APT') {
    jQuery('#billing_shipping_type').val('office2').trigger('change');
  }

  if (returnedOfficeJsonObject.type == 'OFFICE') {
    jQuery('#billing_shipping_type').val('office').trigger('change');
  }

  // FOREIGN: пишем в abroad полетата + city + синхронизираме стандартното office поле
  if (isForeign) {
    var officeId = returnedOfficeJsonObject.id || returnedOfficeJsonObject.officeId || '';
    var officeName = returnedOfficeJsonObject.name || returnedOfficeJsonObject.description || '';
    var officeAddress = getOfficeAddressText(returnedOfficeJsonObject);

    var officeText = officeName;
    if (officeAddress) {
      officeText = officeName ? (officeName + ', ' + officeAddress) : officeAddress;
    }

    jQuery('#billing_abroadoffice').val(officeText);
    jQuery('#billing_abroadoffice_id').val(officeId);

    // city/site sync за foreign
    var siteId = returnedOfficeJsonObject.siteId || (returnedOfficeJsonObject.address && returnedOfficeJsonObject.address.siteId) || '';
    var siteName = getOfficeSiteName(returnedOfficeJsonObject);

    if (siteId) {
      jQuery('#city_id').val(siteId);
    }

    if (!siteName && officeAddress) {
      // fallback: първата част от адреса като име на град
      siteName = officeAddress.split(',')[0] || '';
    }

    markCityFieldAsFilled(siteName);

    // fallback към стандартния billing_city ако няма billing_city_text
    if (!jQuery('#billing_city_text').length && jQuery('#billing_city').length && siteName) {
      jQuery('#billing_city').val(siteName).trigger('change');
    }

    if (jQuery('#billing_office').length && officeId !== '') {
      if (jQuery('#billing_office option[value="' + officeId + '"]').length === 0) {
        jQuery('#billing_office').append(jQuery('<option>', { value: officeId, text: officeText }));
      }
      jQuery('#billing_office').val(officeId).trigger('change');
            return;
    }

    jQuery(document.body).trigger('update_checkout');
    return;
  }

  // BG: старата логика
  if (jQuery('#billing_city').val() != returnedOfficeJsonObject.siteId) {
    jQuery('#billing_city').val(returnedOfficeJsonObject.siteId).trigger('change');
  }

  setTimeout(function() {
    jQuery('#billing_office').val(returnedOfficeJsonObject.id).trigger('change');
  }, 1700);

}, false);


});

jQuery(document).ready(function($) {

function fixStateCityOrder() {
    var $stateField = $('#billing_state_field');
    var $cityField = $('#billing_city_field');

    if($stateField.length && $cityField.length) {
        $stateField.removeClass('form-row-last').addClass('form-row-first');
        $cityField.removeClass('form-row-first').addClass('form-row-last');
        $cityField.before($stateField);
    }
}

// при първоначално зареждане
setTimeout(fixStateCityOrder, 100);

// при всяко обновление на WooCommerce
jQuery(document.body).on('updated_checkout', function() {
    setTimeout(fixStateCityOrder, 100);
});


    // Функция за обновяване на адреса
    function updateBillingAddress() {
        var selectedText = $('#billing_office option:selected').text().trim();
        $('#billing_address_1').val(selectedText);

        // Пример: ако искаш да скриваш други полета при някои офиси
        if(selectedText.toLowerCase().includes("сердика")) { // например условие
            $('#billing_address_2, #billing_city').css({
                'display': 'none',
                'visibility': 'hidden',
                'height': '0'
            });
        } else {
            $('#billing_address_2, #billing_city').css({
                'display': '',
                'visibility': '',
                'height': ''
            });
        }
    }

    // Първоначално попълваме, ако има избрана опция
    updateBillingAddress();

    // Следим за промяна на select
   $('#billing_office').on('change', function() {
        updateBillingAddress();
        jQuery(document.body).trigger('update_checkout');
    });
});




jQuery(document.body).ready(function($) {

    function ensureSpeedyCountryIdHidden() {
        if (!jQuery('#speedy_country_id').length) {
            jQuery('<input>', {
                type: 'hidden',
                id: 'speedy_country_id',
                name: 'speedy_country_id',
                value: ''
            }).appendTo('form.checkout');
        }
    }

    function setSpeedyCountryIdHidden(val) {
        ensureSpeedyCountryIdHidden();
        jQuery('#speedy_country_id').val(val || '');
    }


    function toggleAddressField() {
        var type = $('#billing_shipping_type').val();
        var addressOneFieldExists = $('#billing_addressonefield').length > 0;
        var $addressRow = $('#billing_address_1').closest('.form-row');

        if (type === 'address') {
            if (addressOneFieldExists) {
                if ($('#billing_address_1').val() === 'Спиди - До адрес') {
                    $('#billing_address_1').val('');
                }
                $addressRow.show();
            } else {
                $('#billing_address_1')
                    .val('Спиди - До адрес');
                $addressRow.hide();
            }
        } else {
            $addressRow.show();
        }
    }

    // при първо зареждане
    toggleAddressField();

    // при промяна на select-a
    $('#billing_shipping_type').on('change', toggleAddressField);

    // ако WooCommerce презарежда checkout-а (AJAX)
    $(document.body).on('updated_checkout', toggleAddressField);





    if ( $('#billing_autoclose').length === 0 && $('#billing_testpusnat').length ) {
        $('#billing_shipping_type option:first').hide();
    }

    if ($('#billing_autoclose').length) {
        $('#billing_shipping_type option[value="office2"]').text(office2NoTestLabel);
    }

   function toggleAddressFields() {
    var shippingType = $('#billing_shipping_type').val();
    var addressOneFieldExists = $('#billing_addressonefield').length > 0;
    var addressType = parseInt(window.speedy_address_type, 10) || 1;

    var addressFields = [
        '#billing_neighborhood',
        '#billing_street',
        '#billing_street_number',
        '#billing_block',
        '#billing_entrance',
        '#billing_floor',
        '#billing_apartment'
    ];

    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isDomesticLike = (iso2 === '' || iso2 === 'BG' || iso2 === 'RO');
    var isForeignNonDomestic = !isDomesticLike;

        if (isForeignNonDomestic && addressType === 2) {
        addressFields.forEach(function(selector) {
            $(selector).closest('.form-row').stop(true, true).hide();
        });
        if (shippingType === 'address' && !addressOneFieldExists) {
            $('#billing_street').closest('.form-row').stop(true, true).show();
            $('#billing_street_number').closest('.form-row').stop(true, true).hide();
        }
        applyOpenModalBtnVisibility();
        return;
    }

    if (shippingType === 'address' && !addressOneFieldExists) {
        addressFields.forEach(function(selector) {
            if (isForeignNonDomestic) {
                if (selector === '#billing_street') {
                    $(selector).closest('.form-row').show();
                } else {
                    $(selector).closest('.form-row').hide();
                }
                return;
            }

            if (isForeignNonDomestic && selector === '#billing_street_number') {
                $(selector).closest('.form-row').stop(true, true).hide();
                return;
            }
            $(selector).closest('.form-row').show();
        });
    } else {
        addressFields.forEach(function(selector) {
            $(selector).closest('.form-row').hide();
        });
    }

    applyOpenModalBtnVisibility();
}

    function toggleAbroadOfficeField() {
            var iso2 = ($('#billing_country').val() || '').toUpperCase();
            var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
            var shippingType = ($('#billing_shipping_type').val() || '');

            // Ensure field wrappers exist
            var $abroadRow = $('#billing_abroadoffice_field');
            if (!$abroadRow.length) return;

            if (isForeign && shippingType === 'office') {
                // show abroad office, hide address fields
                $abroadRow.show();
                $('#billing_street_field, #billing_street2_field, #billing_address_1_field').hide();
            } else {
                $abroadRow.hide();
                // show address fields only when address type
                if (shippingType === 'address') {
                    $('#billing_street_field, #billing_street2_field, #billing_address_1_field').show();
                }
            }
        }

        // call from appropriate places
        $(document.body).on('change', '#billing_country, #billing_shipping_type', toggleAbroadOfficeField);
        $(document.body).on('updated_checkout', toggleAbroadOfficeField);
        toggleAbroadOfficeField();

        var speedyHasOfficesCache = {};
var speedyOfficeState = {
    loading: false,
    hasOffices: true,
    iso2: ''
};

function applyOpenModalBtnVisibility() {
    var shippingType = ($('#billing_shipping_type').val() || '');
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isBG = (iso2 === '' || iso2 === 'BG' || iso2 === 'RO');

    var $select = $('#billing_shipping_type');
    var $optOffice = $select.find('option[value="office"]');
    var $optAuto = $select.find('option[value="office2"]');

    if (isBG) {
        $optOffice.show();
        $optAuto.show();
    } else {
        // В чужбина автоматът винаги е скрит
        $optAuto.hide();

        // Офис в чужбина: само ако държавата има офиси
        var allowOffice = (!speedyOfficeState.loading && !!speedyOfficeState.hasOffices);
        $optOffice.toggle(allowOffice);

        // Ако в момента е избран office, но няма офиси -> превключваме към address
        if (!allowOffice && shippingType === 'office') {
            $select.val('address').trigger('change');
            shippingType = 'address';
        }
    }

    // Бутон карта: само когато не е address и има офиси (за foreign)
    if (shippingType === 'address') {
        $('#openModalBtn').hide();
        return;
    }

    if (isBG) {
        $('#openModalBtn').show();
        return;
    }

    if (speedyOfficeState.loading) {
        $('#openModalBtn').hide();
        return;
    }

    $('#openModalBtn').toggle(!!speedyOfficeState.hasOffices);
}

function fetchCountryHasOffices(iso2, done) {
    iso2 = (iso2 || '').toUpperCase();

    if (iso2 === '' || iso2 === 'BG' || iso2 === 'RO') {
        done(true);
        return;
    }

    if (Object.prototype.hasOwnProperty.call(speedyHasOfficesCache, iso2)) {
        done(!!speedyHasOfficesCache[iso2]);
        return;
    }

    $.post(
        shipping_settings.ajax_url,
        {
            action: 'speedy_get_offices',
            // short probe query; достатъчно за проверка дали има поне 1 офис
            name: 'a',
            countryIso: iso2
        },
        function(res) {
            var payload = (res && res.success) ? res.data : null;
            var offices = (payload && payload.offices) ? payload.offices : payload;
            var hasOffices = Array.isArray(offices) && offices.length > 0;

            speedyHasOfficesCache[iso2] = hasOffices;
            done(hasOffices);
        },
        'json'
    ).fail(function() {
        speedyHasOfficesCache[iso2] = false;
        done(false);
    });
}

function refreshOpenModalBtnByCountry() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();

    speedyOfficeState.iso2 = iso2;

    if (iso2 === '' || iso2 === 'BG' || iso2 === 'RO') {
        speedyOfficeState.loading = false;
        speedyOfficeState.hasOffices = true;
        applyOpenModalBtnVisibility();
        return;
    }

    speedyOfficeState.loading = true;
    applyOpenModalBtnVisibility();

    fetchCountryHasOffices(iso2, function(hasOffices) {
        // пазим се от out-of-order отговори
        var currentIso = ($('#billing_country').val() || '').toUpperCase();
        if (currentIso !== iso2) return;

        speedyOfficeState.loading = false;
        speedyOfficeState.hasOffices = hasOffices;
        applyOpenModalBtnVisibility();
    });
}

   function toggleOfficeForForeign() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isBG = (iso2 === 'BG' || iso2 === '' || iso2 === 'RO');
    var shippingType = ($('#billing_shipping_type').val() || '');

    var showOfficeField = isBG && (shippingType === 'office' || shippingType === 'office2');
    $('#billing_office_field').toggle(showOfficeField);

    // бутонът карта е за всички office режими, скрит само при address
   applyOpenModalBtnVisibility();
}

$(document.body).on('change', '#billing_country, #billing_shipping_type', toggleOfficeForForeign);
$(document.body).on('updated_checkout', toggleOfficeForForeign);
toggleOfficeForForeign();

    // Run on page load
    toggleAddressFields();

    // Run on change
    $('#billing_shipping_type').on('change', function() {
        toggleAddressFields();
    });

    function adjustShippingTypeForForeign() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
    var isBG = !isForeign;
    var $select = $('#billing_shipping_type');
    var current = $select.val();

    // чужбина: крием автомат
    var $optAuto = $select.find('option[value="office2"]');
    if (isForeign) {
        $optAuto.hide();
        if (current === 'office2') {
            $select.val('office').trigger('change');
            current = 'office';
        }
    } else {
        $optAuto.show();
    }

    // бутон карта: скрит само при address
   applyOpenModalBtnVisibility();

    // office полето: само BG + office/office2
    var showOfficeField = isBG && (current === 'office' || current === 'office2');
    $('#billing_office_field').toggle(showOfficeField);
}

$(document.body).on('change', '#billing_country, #billing_shipping_type', adjustShippingTypeForForeign);
$(document.body).on('updated_checkout', adjustShippingTypeForForeign);
adjustShippingTypeForForeign();
$(document.body).on('change', '#billing_country', refreshOpenModalBtnByCountry);
$(document.body).on('change', '#billing_shipping_type', applyOpenModalBtnVisibility);
$(document.body).on('updated_checkout', applyOpenModalBtnVisibility);

// initial
refreshOpenModalBtnByCountry();

});

jQuery(function($) {
    function updatePlaceholder() {
        var addressType = parseInt(window.speedy_address_type, 10) || 1;
        var shippingType = ($('#billing_shipping_type').val() || '');
        var iso2 = ($('#billing_country').val() || '').toUpperCase();
        var isDomesticLike = (iso2 === '' || iso2 === 'BG' || iso2 === 'RO');
        var usePostcodeField = (shippingType === 'address' && (addressType === 2 || !isDomesticLike));

        // При addressType=2 или чужбина (без Румъния) и доставка до адрес -> billing_address_2 е ПОЩЕНСКИ КОД
        if (usePostcodeField) {
            $('#billing_address_2').attr('placeholder', postcodeLabel);

            $('#billing_address_2_field label').each(function() {
                var $lbl = $(this);

                if (!$lbl.data('orig-text')) {
                    $lbl.data('orig-text', $lbl.text());
                }

                $lbl.text(postcodeLabel);
            });

            return;
        }

        $('#billing_address_2').attr('placeholder', addressNotePlaceholderLabel);

        $('#billing_address_2_field label').each(function() {
            var $lbl = $(this);
            var orig = $lbl.data('orig-text');
            if (orig) {
                $lbl.text(orig);
            }
        });
    }

    function applyPostcodeWidthRule() {
        var iso2 = ($('#billing_country').val() || '').toUpperCase();
        var isBG = (iso2 === 'BG' || iso2 === '' || iso2 === 'RO');
        var $row = $('#billing_address_2_field');
        var $input = $('#billing_address_2');

        if (!$row.length || !$input.length) return;

        var ph = ($input.attr('placeholder') || '').trim().toUpperCase();
        var isPostcode = (ph === String(postcodeLabel).trim().toUpperCase());

        if (!isBG && isPostcode) {
            $row.css('width', '226px');
            $input.css('width', '100%');
        } else {
            $row.css('width', '');
            $input.css('width', '');
        }
    }

    // NEW: За чужбина сменяме label-а на "Улица" -> "Адрес (1)"
    function updateStreetLabelByCountry() {
        var iso2 = ($('#billing_country').val() || '').toUpperCase();
        var isBG = (iso2 === 'BG' || iso2 === '' || iso2 === 'RO');

        var $lbl1 = $('#billing_street_field label');
        var $lbl2 = $('#billing_street2_field label');

        if (!$lbl1.length && !$lbl2.length) return;

        if ($lbl1.length && !$lbl1.data('orig-html')) {
            $lbl1.data('orig-html', $lbl1.html());
        }
        if ($lbl2.length && !$lbl2.data('orig-html')) {
            $lbl2.data('orig-html', $lbl2.html());
        }

        if (!isBG) {
            if ($lbl1.length) $lbl1.html('Адрес&nbsp;(1)&nbsp;');
            if ($lbl2.length) $lbl2.html('Адрес&nbsp;(2)&nbsp;'); // махаме "(по избор)"
        } else {
            if ($lbl1.length) $lbl1.html($lbl1.data('orig-html'));
            if ($lbl2.length) $lbl2.html($lbl2.data('orig-html'));
        }
    }


    // NEW: пускаме го "след" като Woo/Speedy евентуално прерисуват полетата
    function updateStreetLabelByCountryDelayed() {
        window.clearTimeout(window.__speedyStreetLblT);
        window.__speedyStreetLblT = window.setTimeout(function() {
            updateStreetLabelByCountry();
        }, 150);
    }

    // при първо зареждане
    updateStreetLabelByCountryDelayed();

    // при смяна на държава (Woo ще прави AJAX и ще прерисува)
    $(document.body).on('change', '#billing_country', updateStreetLabelByCountryDelayed);

    // при всяко AJAX обновяване на checkout-а
    $(document.body).on('updated_checkout', updateStreetLabelByCountryDelayed);

   function reorderForeignMainFields() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isBG = (iso2 === 'BG' || iso2 === '' || iso2 === 'RO');

    var $postcodeRow = $('#billing_address_2_field');
    var $cityRow = $('#billing_city_field');
    var $shippingTypeRow = $('#billing_shipping_type_field');

    if (!$postcodeRow.length || !$cityRow.length || !$shippingTypeRow.length) return;

    // пазим оригинала за връщане при BG
    if (!$postcodeRow.data('orig-parent')) {
        $postcodeRow.data('orig-parent', $postcodeRow.parent());
        $postcodeRow.data('orig-next', $postcodeRow.next().length ? $postcodeRow.next() : null);
    }
    if (!$cityRow.data('orig-parent')) {
        $cityRow.data('orig-parent', $cityRow.parent());
        $cityRow.data('orig-next', $cityRow.next().length ? $cityRow.next() : null);
    }
    if (!$shippingTypeRow.data('orig-parent')) {
        $shippingTypeRow.data('orig-parent', $shippingTypeRow.parent());
        $shippingTypeRow.data('orig-next', $shippingTypeRow.next().length ? $shippingTypeRow.next() : null);
    }

    if (!isBG) {
        // foreign: ПОЩЕНСКИ КОД -> ГРАД -> ДОСТАВКА ДО
        $postcodeRow.insertBefore($cityRow);
        $shippingTypeRow.insertAfter($cityRow);

        // по желание: и трите да са wide, за да няма визуално размятане
        $postcodeRow.removeClass('form-row-first form-row-last').addClass('form-row-wide');
        $cityRow.removeClass('form-row-first form-row-last').addClass('form-row-wide');
        $shippingTypeRow.removeClass('form-row-first form-row-last').addClass('form-row-wide');
    } else {
        // връщане на оригинални позиции
        [$postcodeRow, $cityRow, $shippingTypeRow].forEach(function($row) {
            var $origParent = $row.data('orig-parent');
            var $origNext = $row.data('orig-next');

            if ($origParent && $origParent.length) {
                if ($origNext && $origNext.length) {
                    $row.insertBefore($origNext);
                } else {
                    $origParent.append($row);
                }
            }
        });
    }
}

function reorderForeignMainFieldsDelayed() {
    window.clearTimeout(window.__speedyForeignMainOrderT);
    window.__speedyForeignMainOrderT = window.setTimeout(reorderForeignMainFields, 150);
}

$(document.body).on('change', '#billing_country', reorderForeignMainFieldsDelayed);
// Изчистване на адресни полета при смяна на държава
$(document.body).on('change', '#billing_country', function() {
    $('#billing_city').val('');
    $('#billing_city_text').val('');
    $('#city_id').val('');
    $('#billing_address_1').val('');
    $('#billing_address_2').val('');
    $('#billing_postcode').val('');
    $('#billing_street').val('');
    $('#billing_street2').val('');
    $('#billing_street_id').val('');
    $('#billing_street_number').val('');
    $('#billing_neighborhood').val('');
    $('#billing_block').val('');
    $('#billing_entrance').val('');
    $('#billing_floor').val('');
    $('#billing_apartment').val('');
    $('#billing_abroadoffice').val('');
    $('#billing_abroadoffice_id').val('');
    $('#billing_office').val('').trigger('change');
    $('#speedy_country_id').val('');
    
    // NEW: тригерваме calculate и при смяна на държава
    $(document.body).trigger('update_checkout');
});
$(document.body).on('updated_checkout', reorderForeignMainFieldsDelayed);
reorderForeignMainFieldsDelayed();

    applyPostcodeWidthRule();
    $(document.body).on('change', '#billing_country, #billing_shipping_type', applyPostcodeWidthRule);
    $(document.body).on('updated_checkout', applyPostcodeWidthRule);



    // при първо зареждане
    updatePlaceholder();

    function toggleForeignAddressLine2() {
        var iso2 = ($('#billing_country').val() || '').toUpperCase();
        var isBG = (iso2 === 'BG' || iso2 === '' || iso2 === 'RO');
        var addressType = parseInt(window.speedy_address_type, 10) || 1;
        var shippingType = ($('#billing_shipping_type').val() || '');

        var $row = $('#billing_street2_field');
        if (!$row.length) return;

        // За всички държави извън BG/RO показваме Адрес (2) при доставка до адрес.
        // BG/RO запазват оригиналното поведение.
        var shouldShow = (!isBG && shippingType === 'address');

        if (shouldShow) {
            $row.stop(true, true).show();

            // позиция: след "Адрес (1)" (billing_street_field)
            var $after = $('#billing_street_field');
            if ($after.length) {
                $row.insertAfter($after);
            }
        } else {
            $row.stop(true, true).hide();
        }
    }

    function toggleForeignAddressLine2Delayed() {
        window.clearTimeout(window.__speedyAddr2T);
        window.__speedyAddr2T = window.setTimeout(function() {
            toggleForeignAddressLine2();
        }, 150);
    }

    // първо зареждане
    toggleForeignAddressLine2Delayed();

    // при смяна на държава / тип доставка / ajax redraw
    $(document.body).on('change', '#billing_country', toggleForeignAddressLine2Delayed);
    $(document.body).on('change', '#billing_shipping_type', toggleForeignAddressLine2Delayed);
    $(document.body).on('updated_checkout', toggleForeignAddressLine2Delayed);

// NEW: trigger update_checkout with debounce for postcode changes
$(document.body).on('input', '#billing_address_2', function() {
    clearTimeout(postCodeTimeout);
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
    var shippingType = ($('#billing_shipping_type').val() || '');
    
    // Trigger calculation after 0.8 seconds of stopped typing
    if (isForeign && shippingType === 'address') {
        postCodeTimeout = setTimeout(function() {
            $(document.body).trigger('update_checkout');
        }, 800);
    }
});

    // при всяко презареждане на checkout формата
    $(document.body).on('updated_checkout', updatePlaceholder);

    // при смяна на типа доставка
    $(document.body).on('change', '#billing_shipping_type', updatePlaceholder);
});





function toggleAddress2Label() {
    var shippingType = jQuery('#billing_shipping_type').val();
    if (shippingType === 'office' || shippingType === 'office2') {
        jQuery('#billing_address_2_field label, #billing_address_2_field span').hide();
    } else {
        jQuery('#billing_address_2_field label, #billing_address_2_field span').show();
    }
}

//autocomplete streets
jQuery(document).ready(function($) {
    var $input = $("#billing_street");

    // Move suggestions into the input wrapper so they sit right under the field
(function(){
  var $input = $('#billing_abroadoffice');
  var $list  = $('#billing_abroadoffice-suggestions');
  if (!$input.length || !$list.length) return;

  var $wrapper = $input.closest('.woocommerce-input-wrapper');
  if (!$wrapper.length) $wrapper = $input.parent();

  $wrapper.css('position', 'relative');

  $list.appendTo($wrapper).css({
    position: 'absolute',
    top: ($input.outerHeight() + 4) + 'px',
    left: 0,
    width: '100%',
    zIndex: 99999,
    background: '#fff',
    boxShadow: '0 2px 6px rgba(0,0,0,0.08)',
    display: 'none'
  });

  function relayoutAbroadList(){
    $list.css({
      top: ($input.outerHeight() + 4) + 'px',
      width: $wrapper.innerWidth() + 'px'
    });
  }

  $input.on('focus input', relayoutAbroadList);
  $(window).on('resize', relayoutAbroadList);
  $(document).on('updated_checkout', relayoutAbroadList);
  relayoutAbroadList();
})();

    var $list = $("<ul>")
        .attr("id", "billing-street-suggestions")
        .css({
            "border": "1px solid #ccc",
            "max-height": "200px",
            "overflow": "auto",
            "list-style": "none",
            "padding": "5px",
            "margin-top": "2px",
            "width": $input.outerWidth(),
            "position": "absolute",
            "background": "#fff",
            "z-index": 9999,
            "display": "none"
        });
    $input.after($list);

    var selectedIndex = -1;

    function highlightItem($items) {
        $items.css({"background": "", "color": ""});
        if (selectedIndex >= 0) {
            $items.eq(selectedIndex).css({"background":"#007BFF","color":"#fff"});
        }
    }

    function selectItem(street) {
        $input.val(street.name);
        if ($("#billing_street_id").length === 0) {
            $("<input>").attr({
                type: "hidden",
                name: "billing_street_id",
                id: "billing_street_id",
                value: street.id
            }).appendTo($input.parent());
        } else {
            $("#billing_street_id").val(street.id);
        }
        $list.empty().hide();
        selectedIndex = -1;

      

    }

    $input.on("input", function() {
        var query = $(this).val().trim();
        var siteId = $("#city_id").val();
        var iso2 = ($('#billing_country').val() || '').toUpperCase();
        var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');
        var hiddenCountryId = parseInt($('#speedy_country_id').val() || '0', 10) || 0;

        if (!siteId || query.length < 2) {
            $list.empty().hide();
            return;
        }

        function doStreetRequest(countryIdValue) {
            $.ajax({
                url: shipping_settings.ajax_url,
                method: 'POST',
                data: {
                    action: 'speedy_get_streets',
                    siteId: siteId,
                    name: query,
                    countryId: parseInt(countryIdValue, 10) || 0
                },
                success: function(response) {
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
                        $list.empty().hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX error:", status, error, xhr.responseText);
                    $list.empty().hide();
                }
            });
        }

        if (!isForeign) {
            doStreetRequest(0);
            return;
        }

        if (hiddenCountryId > 0) {
            doStreetRequest(hiddenCountryId);
            return;
        }

        if (typeof window.speedyGetCountryId !== 'function') {
            console.error('speedyGetCountryId is not available');
            $list.empty().hide();
            return;
        }

        window.speedyGetCountryId(iso2, function(cidRaw) {
            var cid = normalizeCountryIdValue(cidRaw);

            if (cid > 0) {
                if (!$('#speedy_country_id').length) {
                    $('<input>', {
                        type: 'hidden',
                        id: 'speedy_country_id',
                        name: 'speedy_country_id',
                        value: String(cid)
                    }).appendTo('form.checkout');
                } else {
                    $('#speedy_country_id').val(String(cid));
                }
            }

            doStreetRequest(cid);
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

    $(document).on("click", function(e) {
        if(!$(e.target).closest("#billing_street, #billing-street-suggestions").length){
            $list.empty().hide();
            selectedIndex = -1;
        }
    });
});


//autocomplete front end (neighborhood)
jQuery(document).ready(function($) {
    var $input = $("#billing_neighborhood");

    var $list = $("<ul>")
        .attr("id", "billing-neighborhood-suggestions")
        .css({
            "border": "1px solid #ccc",
            "max-height": "200px",
            "overflow": "auto",
            "list-style": "none",
            "padding": "5px",
            "margin-top": "2px",
            "width": $input.outerWidth(),
            "position": "absolute",
            "background": "#fff",
            "z-index": 9999,
            "display": "none"
        });
    $input.after($list);

    var selectedIndex = -1;

    function highlightItem($items) {
        $items.css({"background": "", "color": ""});
        if (selectedIndex >= 0) {
            $items.eq(selectedIndex).css({"background":"#007BFF","color":"#fff"});
        }
    }

    function selectItem(complex) {
        $input.val(complex.name);
        if ($("#billing_neighborhood_id").length === 0) {
            $("<input>").attr({
                type: "hidden",
                name: "billing_neighborhood_id",
                id: "billing_neighborhood_id",
                value: complex.id
            }).appendTo($input.parent());
        } else {
            $("#billing_neighborhood_id").val(complex.id);
        }
        $list.empty().hide();
        selectedIndex = -1;
    }

    $input.on("input", function() {
        var query = $(this).val();
        var siteId = $("#city_id").val();

        if (!siteId || query.length < 2) {
            $list.empty().hide();
            return;
        }

        $.ajax({
            url: shipping_settings.ajax_url,
            method: 'POST',
            data: {
                action: 'speedy_get_complexes',
                siteId: siteId,
                name: query
            },
            success: function(response) {
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
                    $list.append("<li style='color:#999;padding:3px;'>Няма резултати</li>").show();
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX error:", status, error, xhr.responseText);
            }
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

    $(document).on("click", function(e) {
        if(!$(e.target).closest("#billing_neighborhood, #billing-neighborhood-suggestions").length){
            $list.empty().hide();
            selectedIndex = -1;
        }
    });
});

jQuery(document).ready(function($){
    $("#billing_neighborhood, #billing_street").attr("autocomplete", "new-password");
});

jQuery(document).ready(function() {
    toggleAddress2Label();
    jQuery('#billing_shipping_type').on('change', toggleAddress2Label);
});


jQuery(document.body).ready(function($) {
  window.chosen_shipping = jQuery('#chosen_method').val();
  window.shipping_type   = jQuery('#billing_shipping_type').val();

  jQuery(document.body).on('change','input[name="shipping_method[0]"]', function(e) {
    window.chosen_shipping = jQuery(this).val();
  });

  if( window.chosen_shipping == '' ) window.chosen_shipping = 'speedy_shipping';

    function getSelectedCountryIso2() {
        return (jQuery('#billing_country').val() || '').toUpperCase();
    }

    function isBG() {
        var iso2 = getSelectedCountryIso2();
        return iso2 === 'BG' || iso2 === '';
    }

    function ensureForeignCityUI() {
        var $cityField = jQuery('#billing_city_field');
        if (!$cityField.length) return;

        if (jQuery('#billing_city_text').length) return;

        var $input = jQuery('<input>', {
            type: 'text',
            id: 'billing_city_text',
            name: 'billing_city',
            autocomplete: 'off',
            placeholder: 'Въведете град',
            class: 'input-text'
        }).css({ width: '100%' });

        var $hiddenSiteId = jQuery('#city_id');
        if (!$hiddenSiteId.length) {
            $hiddenSiteId = jQuery('<input>', { type: 'hidden', id: 'city_id', name: 'city_id', value: '' });
            $cityField.append($hiddenSiteId);
        }

        var $list = jQuery('<ul>', { id: 'billing-city-suggestions' }).css({
            border: '1px solid #ccc',
            'max-height': '200px',
            overflow: 'auto',
            'list-style': 'none',
            padding: '5px',
            'margin-top': '2px',
            background: '#fff',
            position: 'absolute',
            width: $cityField.outerWidth(),
            'z-index': 9999,
            display: 'none'
        });

        $cityField.css({ position: 'relative' });

        // Скриваме wrapper-а, за да не стои select-а "отгоре"
        $cityField.find('.woocommerce-input-wrapper').hide();

        // За всеки случай и самия select
        $cityField.find('select#billing_city').hide().prop('disabled', true);

        $cityField.append($input);
        $cityField.append($list);
    }


    var speedyCountryIdCache = {};
    var speedyCountryInfoCache = {};

    function getSpeedyCountryInfo(iso2, cb) {
    iso2 = (iso2 || '').toUpperCase();

    if (speedyCountryInfoCache[iso2]) return cb(speedyCountryInfoCache[iso2]);

    jQuery.ajax({
        url: shipping_settings.ajax_url,
        method: 'POST',
        data: { action: 'speedy_get_country_id', iso2: iso2 },
        success: function(res) {
            if (res && res.success && res.data && res.data.countryId) {
                var info = {
                    countryId: parseInt(res.data.countryId, 10) || 0,
                    addressType: parseInt(res.data.addressType, 10) || 1
                };
                speedyCountryInfoCache[iso2] = info;
                speedyCountryIdCache[iso2] = info.countryId;
                cb(info);
                return;
            }
            cb(null);
        },
        error: function() { cb(null); }
    });
}

 

 function getSpeedyCountryId(iso2, cb) {
    iso2 = (iso2 || '').toUpperCase();

    if (speedyCountryIdCache[iso2]) return cb(speedyCountryIdCache[iso2]);

    getSpeedyCountryInfo(iso2, function(info) {
        if (info && info.countryId) {
            var cid = parseInt(info.countryId, 10) || 0;
            var at  = parseInt(info.addressType, 10) || 1;

            speedyCountryIdCache[iso2] = cid;

            window.speedy_address_type = at;
            applySpeedyAddressType(at);

            // ← ДОБАВЯМЕ: записваме countryId в hidden input-а
            if (cid > 0) {
                setSpeedyCountryIdHidden(String(cid));
            }

            if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
                window.speedySyncDetailedAddressFieldsVisibility();
            }

            cb(cid);
            return;
        }
        cb(0);
    });
}


    function applySpeedyAddressType(addressType) {
        // addressType: 1 = детайлен адрес, 2 = адрес в едно поле
        var t = parseInt(addressType, 10) || 1;

        // NEW: пазим текущия addressType глобално, за да не се “препокрива” от други toggle-и
        window.speedy_address_type = t;

        if (typeof speedyApplyAddressTypeBodyClasses === 'function') {
            speedyApplyAddressTypeBodyClasses();
        }

        var toToggle = [
            '#billing_street_number_field',
            '#billing_block_field',
            '#billing_entrance_field',
            '#billing_floor_field',
            '#billing_apartment_field',
            '#billing_neighborhood_field'
        ];

        if (t === 2) {
            toToggle.forEach(function(sel) {
                jQuery(sel).hide();
            });
        } else {
            toToggle.forEach(function(sel) {
                jQuery(sel).show();
            });
        }

        // NEW: след прилагане на addressType, насилваме синхронизация на видимостта
        if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
            window.speedySyncDetailedAddressFieldsVisibility();
        }
    }

    // NEW: глобална функция (да е достъпна от всички ready блокове и updated_checkout)
    window.speedySyncDetailedAddressFieldsVisibility = function() {
        var shippingType = jQuery('#billing_shipping_type').val();
        var addressType = parseInt(window.speedy_address_type, 10) || 1;
        var addressOneFieldExists = jQuery('#billing_addressonefield').length > 0;
        var iso2 = (jQuery('#billing_country').val() || '').toUpperCase();
        var isDomesticLike = (iso2 === '' || iso2 === 'BG' || iso2 === 'RO');

        var simplifiedForeignRows = [
            '#billing_neighborhood_field',
            '#billing_street_number_field',
            '#billing_block_field',
            '#billing_entrance_field',
            '#billing_floor_field',
            '#billing_apartment_field'
        ];

        if (!isDomesticLike) {
            if (shippingType === 'address' && !addressOneFieldExists) {
                jQuery('#billing_street_field').stop(true, true).show();
                simplifiedForeignRows.forEach(function(sel) {
                    jQuery(sel).stop(true, true).hide();
                });
            } else {
                jQuery('#billing_street_field').stop(true, true).hide();
                simplifiedForeignRows.forEach(function(sel) {
                    jQuery(sel).stop(true, true).hide();
                });
            }
            return;
        }

        // редове (по-надеждно е да управляваме *_field)
        var rowsAlwaysHideForAddressType2 = [
            '#billing_neighborhood_field',
            '#billing_block_field',
            '#billing_entrance_field',
            '#billing_floor_field',
            '#billing_apartment_field'
        ];

        var rowsWeMayShow = [
            '#billing_street_field',
            '#billing_street_number_field'
        ];

        // IMPORTANT: при addressType=2 крием само тези, които не трябва да ги има,
        // но оставяме "Улица" (и по желание "№") да работят при "Адрес".
        if (addressType === 2) {
            rowsAlwaysHideForAddressType2.forEach(function(sel) {
                jQuery(sel).stop(true, true).hide();
            });

            if (shippingType === 'address' && !addressOneFieldExists) {
                // Улица да се вижда
                jQuery('#billing_street_field').stop(true, true).show();

                // Ако НЕ искаш "№:" за Гърция, смени show() на hide()
                jQuery('#billing_street_number_field').stop(true, true).hide();
            } else {
                // при офис/автомат – криеш и улица/№
                rowsWeMayShow.forEach(function(sel) {
                    jQuery(sel).stop(true, true).hide();
                });
            }

            return;
        }

        // addressType=1 -> показваме детайлните полета само при "Адрес"
        var detailedFieldsRows = [
            '#billing_neighborhood_field',
            '#billing_street_field',
            '#billing_street_number_field',
            '#billing_block_field',
            '#billing_entrance_field',
            '#billing_floor_field',
            '#billing_apartment_field'
        ];

        if (shippingType === 'address' && !addressOneFieldExists) {
            detailedFieldsRows.forEach(function(sel) {
                jQuery(sel).stop(true, true).show();
            });
        } else {
            detailedFieldsRows.forEach(function(sel) {
                jQuery(sel).stop(true, true).hide();
            });
        }
    };

    window.speedyValidateBillingStreet = function(options) {
        var $field = jQuery('#billing_street');
        var $row = jQuery('#billing_street_field');
        var $error = $row.find('.speedy-street-error');

        $field.removeAttr('aria-invalid');
        $row.removeClass('woocommerce-invalid woocommerce-validated');
        $error.remove();
        return true;
    };

    function bindStreetValidationSubmitHook() {
        var $checkoutForm = jQuery('form.checkout');
        if (!$checkoutForm.length) return;

        $checkoutForm.off('checkout_place_order.speedyStreetValidation');
        $checkoutForm.on('checkout_place_order.speedyStreetValidation', function() {
            return window.speedyValidateBillingStreet({ focus: true });
        });
    }

    // NEW: при всяко AJAX обновяване на checkout-а пак прилагаме правилото
    jQuery(document.body).on('updated_checkout', function() {
        if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
            window.speedySyncDetailedAddressFieldsVisibility();
        }

        if (typeof window.speedyValidateBillingStreet === 'function') {
            window.speedyValidateBillingStreet();
        }

        bindStreetValidationSubmitHook();
    });

    jQuery(document.body).on('change', '#billing_country, #billing_shipping_type', function() {
        if (typeof window.speedyValidateBillingStreet === 'function') {
            window.speedyValidateBillingStreet();
        }
    });

    jQuery(document.body).on('input change blur', '#billing_street', function() {
        if (typeof window.speedyValidateBillingStreet === 'function') {
            window.speedyValidateBillingStreet();
        }
    });

    bindStreetValidationSubmitHook();


  


    // NEW: да е достъпна от всички jQuery ready блокове
    window.speedyGetCountryId = getSpeedyCountryId;


    function fillOfficesSelect(res) {
        var $officeField = jQuery('#billing_office_field');
        var $officeSelect = jQuery('#billing_office');
        var $shippingType = jQuery('#billing_shipping_type');

      function updateAddressFields() {
if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
window.speedySyncDetailedAddressFieldsVisibility();
}
}

        $officeSelect.empty();

        var data = (res && res.data) ? res.data : [];

                var _iso2check = ($('#billing_country').val() || '').toUpperCase();
        var _isForeign = (_iso2check !== '' && _iso2check !== 'BG');

        if (data.length === 0) {
            $officeField.hide();
            if (!_isForeign) {
                $shippingType.val('address');
                $shippingType.find('option').each(function() {
                    if (jQuery(this).val() !== 'address') jQuery(this).hide();
                    else jQuery(this).show();
                });
            }
            updateAddressFields();
            return;
        } else {
            $officeField.show();
            $shippingType.find('option').show();
        }

        var hasAutomats = data.some(function(office) {
            var name = (office.name || '').toUpperCase();
            return name.includes("АВТОМАТ") || name.includes("ЕКОНТОМАТ") || name.includes("APT");
        });

        var hasOffices = data.some(function(office) {
            var name = (office.name || '').toUpperCase();
            return !(name.includes("АВТОМАТ") || name.includes("ЕКОНТОМАТ") || name.includes("APT"));
        });

        if (_isForeign) {
            $shippingType.find('option[value="office"]').show();
            $shippingType.find('option[value="office2"]').hide();
        } else {
            $shippingType.find('option[value="office"]').toggle(hasOffices);
            $shippingType.find('option[value="office2"]').toggle(hasAutomats);
        }
        $shippingType.find('option[value="address"]').show();

        if (window.shipping_type === 'office' && hasOffices) {
            $officeSelect.append(`<option selected disabled>${officePlaceholderLabel}</option>`);
        } else if (window.shipping_type === 'office2' && hasAutomats) {
            $officeSelect.append(`<option selected disabled>${automatPlaceholderLabel}</option>`);
        }

        for (var i = 0; i < data.length; i++) {
            var office = data[i];
            var officeName = office.name || '';
            var officeAddress = office.address || '';
            var isAutomat = officeName.toUpperCase().includes("АВТОМАТ") || officeName.toUpperCase().includes("ЕКОНТОМАТ") || officeName.toUpperCase().includes("APT");

            if (window.shipping_type === 'office2' && isAutomat) {
                $officeSelect.append('<option value="' + office.id + '">' + office.id + ' ' + officeName + ', ' + officeAddress + '</option>');
            } else if (window.shipping_type === 'office' && !isAutomat) {
                $officeSelect.append('<option value="' + office.id + '">' + office.id + ' ' + officeName + ', ' + officeAddress + '</option>');
            }
        }

        $officeSelect.select2({
            language: {
                noResults: function () {
                    return "След избор на населено място ще ви се заредят офиси.";
                }
            }
        });

        $officeField.fadeIn();

        if (data.length === 1) {
            jQuery(document.body).trigger('update_checkout');
        }

        updateAddressFields();

        $shippingType.off('change.updateFields').on('change.updateFields', function() {
            updateAddressFields();
        });
    }

    function fetchOfficesForeign(siteId, countryId) {
        jQuery.ajax({
            url: shipping_settings.ajax_url,
            method: 'POST',
            data: {
                action: 'speedy_get_offices_by_site',
                siteId: siteId,
                countryId: countryId
            },
            success: function(res) {
                fillOfficesSelect(res);
            },
            error: function() {
                fillOfficesSelect({ data: [] });
            }
        });
    }

    function setupForeignCityAutocomplete(countryIso2) {
        ensureForeignCityUI();

        var $input = jQuery('#billing_city_text');
        var $list = jQuery('#billing-city-suggestions');
        var $hiddenSiteId = jQuery('#city_id');

        var selectedIndex = -1;

        function highlightItem($items) {
            $items.css({ background: '', color: '' });
            if (selectedIndex >= 0) {
                $items.eq(selectedIndex).css({ background: '#007BFF', color: '#fff' });
            }
        }

        function selectItem(site, countryId) {
            $input.val(site.name);
            $hiddenSiteId.val(site.id);
            $list.empty().hide();
            selectedIndex = -1;

            fetchOfficesForeign(site.id, countryId);
            jQuery(document.body).trigger('update_checkout');
        }

        $input.off('input.speedyForeign').on('input.speedyForeign', function() {
            var q = jQuery(this).val().trim();
            $hiddenSiteId.val('');

            if (q.length < 2) {
                $list.empty().hide();
                return;
            }

            getSpeedyCountryId(countryIso2, function(countryId) {
                if (!countryId) {
                    //$list.empty().html("<li style='color:#999;padding:3px;'>Не може да се вземе countryId за " + countryIso2 + "</li>").show();
                    $list.empty().hide();
                    return;
                }

                jQuery.ajax({
                    url: shipping_settings.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'speedy_get_sites',
                        countryId: countryId,
                        stateCode: (jQuery('#billing_state').val() || ''),
                        name: q
                    },
                    success: function(response) {
                        $list.empty();
                        selectedIndex = -1;

                        if (response && response.length) {
                            response.forEach(function(site) {
                                var $item = jQuery('<li>')
                                    .text(site.name)
                                    .attr('data-id', site.id)
                                    .css({ cursor: 'pointer', padding: '3px' })
                                    .on('click', function() { selectItem(site, countryId); });
                                $list.append($item);
                            });
                            $list.show();
                        } else {
                            //$list.append("<li style='color:#999;padding:3px;'>Няма резултати</li>").show();
                            $list.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('speedy_get_sites ajax error', status, error, xhr.responseText);
                    }
                });
            });
        });

       $input.off('keydown.speedyForeign').on('keydown.speedyForeign', function(e) {
    var $items = $list.find('li');

    // Stop checkout form submit while typing/selecting city suggestions
    if (e.key === 'Enter') {
        e.preventDefault();

        if (!$list.is(':visible') || !$items.length) {
            return;
        }

        // If nothing is highlighted, pick the first suggestion
        if (selectedIndex < 0) {
            selectedIndex = 0;
            highlightItem($items);
        }

        $items.eq(selectedIndex).trigger('click');
        return;
    }

    if (!$items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % $items.length;
        highlightItem($items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
        highlightItem($items);
    }
});

        jQuery(document).off('click.speedyForeign').on('click.speedyForeign', function(e) {
            if (!jQuery(e.target).closest('#billing_city_text, #billing-city-suggestions').length) {
                $list.empty().hide();
                selectedIndex = -1;
            }
        });
    }

    function toggleCityModeByCountry() {
        var iso2 = getSelectedCountryIso2();

        if (iso2 === 'BG' || iso2 === '') {
            applySpeedyAddressType(1);


            jQuery('#billing_city_text').remove();
            jQuery('#billing-city-suggestions').remove();

            var $cityField = jQuery('#billing_city_field');
            $cityField.find('.woocommerce-input-wrapper').show();
            $cityField.find('#billing_city').show().prop('disabled', false);

            // If checkout was refreshed and city options are empty, reload from selected BG region.
            var $city = jQuery('#billing_city');
            if ($city.length && $city.find('option').length <= 1) {
                jQuery('#billing_state').trigger('change');
            }

            return;
        }

        getSpeedyCountryInfo(iso2, function(info) {
            if (info && info.addressType) {
                applySpeedyAddressType(info.addressType);
            }
        });

        setupForeignCityAutocomplete(iso2);

    }


    jQuery(document.body).on('change', '#billing_country', function() {
        toggleCityModeByCountry();
    });

    toggleCityModeByCountry();
    jQuery(document.body).on('updated_checkout', function() {
        toggleCityModeByCountry();
    });

    function fetchOffices(city) {
        var action = '';
        var payload = {};
        var siteLanguage = (window.shipping_settings && shipping_settings.office_locator_lang)
                ? String(shipping_settings.office_locator_lang).toLowerCase()
                : 'bg';
        var selectedCityId = parseInt(jQuery('#billing_city').val() || '0', 10) || 0;

        if(window.chosen_shipping === 'econt_shipping') {
            action = 'get_offices_by_city';
            payload = {
                action: action,
                city: city
            };
        } else if (siteLanguage !== 'bg' && selectedCityId > 0) {
            action = 'speedy_get_offices_by_site';
            payload = {
                action: action,
                siteId: selectedCityId,
                countryId: 100
            };
        } else {
            action = 'speedy_get_offices_by_city';
            payload = {
                action: action,
                city: city
            };
        }

    jQuery.ajax({
      url: shipping_settings.ajax_url,
      method: 'POST',
            data: payload,
      success: function(res) {
    var $officeField = jQuery('#billing_office_field');
    var $officeSelect = jQuery('#billing_office');
    var $shippingType = jQuery('#billing_shipping_type');

  function updateAddressFields() {
if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
window.speedySyncDetailedAddressFieldsVisibility();
}
}

    // Премахваме старите опции
    $officeSelect.empty();

        var _iso2check = ($('#billing_country').val() || '').toUpperCase();
    var _isForeign = (_iso2check !== '' && _iso2check !== 'BG');

    if(res.data.length === 0) {
        // Няма офис или автомат
        $officeField.hide();
        if (!_isForeign) {
            $shippingType.val('address');
            $shippingType.find('option').each(function() {
                if(jQuery(this).val() !== 'address') {
                    jQuery(this).hide();
                } else {
                    jQuery(this).show();
                }
            });
        }
        updateAddressFields();
        return;
    } else {
        $officeField.show();
        $shippingType.find('option').show();
    }

    // Проверяваме дали има автомати и дали има офиси
    var hasAutomats = res.data.some(function(office) {
        var name = office.name.toUpperCase();
        return name.includes("АВТОМАТ") || name.includes("ЕКОНТОМАТ");
    });
    var hasOffices = res.data.some(function(office) {
        var name = office.name.toUpperCase();
        return !(name.includes("АВТОМАТ") || name.includes("ЕКОНТОМАТ"));
    });

    // Скриваме опциите според наличното
    if (_isForeign) {
        $shippingType.find('option[value="office"]').show();
        $shippingType.find('option[value="office2"]').hide();
    } else {
        $shippingType.find('option[value="office"]').toggle(hasOffices);
        $shippingType.find('option[value="office2"]').toggle(hasAutomats);
    }
    $shippingType.find('option[value="address"]').show();

    // Добавяме placeholder според типа
    if (window.shipping_type === 'office' && hasOffices) {
        $officeSelect.append(`<option selected disabled>${officePlaceholderLabel}</option>`);
    } else if (window.shipping_type === 'office2' && hasAutomats) {
        $officeSelect.append(`<option selected disabled>${automatPlaceholderLabel}</option>`);
    }

    // Попълваме офисите/автоматите
    for(var i = 0; i < res.data.length; i++) {
        var office = res.data[i];
        var office_name = office.name;
        var prefix = '';
        var isAutomat = office_name.toUpperCase().includes("АВТОМАТ") || office_name.toUpperCase().includes("ЕКОНТОМАТ");

        if(window.shipping_type === 'office2' && isAutomat) {
            if (office_name.toUpperCase().includes("АВТОМАТ")) prefix = '[АВТОМАТ] ';
            if (office_name.toUpperCase().includes("ЕКОНТОМАТ")) prefix = '[ЕКОНТОМАТ] ';
            $officeSelect.append('<option value="' + office.id + '">' + office.id + ' ' + prefix + office.name + ', ' + office.address + '</option>');
        } else if(window.shipping_type === 'office' && !isAutomat) {
            $officeSelect.append('<option value="' + office.id + '">' + office.id + ' ' + prefix + office.name + ', ' + office.address + '</option>');
        }
    }



    // Select2
    $officeSelect.select2({
        language: {
            noResults: function () {
                return "След избор на населено място ще ви се заредят офиси.";
            }
        }
    });

    $officeField.fadeIn();

    if(res.data.length === 1) {
        jQuery(document.body).trigger('update_checkout');
    }

    updateAddressFields();

    $shippingType.off('change.updateFields').on('change.updateFields', function() {
        updateAddressFields();
    });
}

,
      error: function() {
        alert('Няма офис в този град');
      }
    });
  }

    function resetOffices() {
        var $select = jQuery('#billing_office');
        $select.empty();
        $select.select2({
            language: {
                noResults: function () {
                    return "След избор на населено място ще ви се заредят офиси.";
                }
            }
        });
    }

    // NEW: пазим адресния тип (1=детайлен, 2=в едно поле)
    window.speedy_address_type = window.speedy_address_type || 1;

    // NEW: единна функция, която ВИНАГИ синхронизира видимостта на адресните полета
   


    jQuery(document).ready(function($) {

        $('#billing_shipping_type').change(function(e) {
            var value = $(this).val();
            window.shipping_type = value;

            // NEW: винаги синхронизираме адресните полета при смяна на типа доставка
            if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
    window.speedySyncDetailedAddressFieldsVisibility();
}

            if (value === 'office' || value === 'office2') {
                $(document.body).removeClass('show-address-fields');

                // FOREIGN: никога не показваме стандартното BG поле за офис
                if (!isBG()) {
                    $('#billing_office_field').hide();

                    // За чужбина работим през foreign flow (city_id + countryId)
                    var siteId = parseInt(jQuery('#city_id').val(), 10) || 0;
                    if (!siteId) return;

                    getSpeedyCountryId(getSelectedCountryIso2(), function(countryId) {
                        if (!countryId) return;
                        fetchOfficesForeign(siteId, countryId);
                    });

                    return;
                }

                // BG: показваме стандартното поле
                if (value === 'office2') {
                    $('#billing_office_field label').html(`${automatLabel}&nbsp;<abbr class="required" title="${requiredTitleLabel}">*</abbr>`);
                }

                if (value === 'office') {
                    $('#billing_office_field label').html(`${officeLabel}&nbsp;<abbr class="required" title="${requiredTitleLabel}">*</abbr>`);
                }

                $('#billing_office_field').show();

                var city = $("#billing_city").find(":selected").text();
                city = city.replace('гр. ', '').replace('с. ', '');
                fetchOffices(city);

            } else {
                $(document.body).addClass('show-address-fields');
                // WooCommerce serializes hidden fields too. Clear the previously
                // selected office so an address calculation cannot reuse it.
                $('#billing_office').val('').trigger('change');
                $('#billing_abroadoffice').val('');
                $('#billing_abroadoffice_id').val('');
                $('#billing_office_field').hide();
            }

        });

        $('#billing_office_field').hide();
        $('#billing_shipping_type').trigger('change');

        // NEW: ако WooCommerce презареди checkout-а, пак синхронизираме (за да не “останат” видими полета)
        $(document.body).on('updated_checkout', function() {
            if (typeof window.speedySyncDetailedAddressFieldsVisibility === 'function') {
                window.speedySyncDetailedAddressFieldsVisibility();
            }
        });

    });



    jQuery(document.body).on('change', '#billing_state', function(e) {
      if (!isBG()) return;

      var selected = jQuery(this).val();
      resetOffices();

      var action = 'speedy_get_cities_by_region';
      if(window.chosen_shipping === 'econt_shipping')
          action = 'get_cities_by_region';

      jQuery.ajax({
          url: shipping_settings.ajax_url,
          method: 'POST',
          data: {
              action: action,
              region: selected
          },
          success: function(res) {
              var select = jQuery('#billing_city');
              select.empty();
              select.append(`<option selected disabled>${cityPlaceholderLabel}</option>`);
              for(var i = 0; i < res.data.length; i++) {
                  var city = res.data[i];
                  select.append(`<option value="${city.id}">${city.type} ${city.name}</option>`);
              }
              select.select2();
          }
      });
  });


    jQuery(document.body).on('change', '#billing_city', function(e) {
        var cityId = jQuery(this).val();
        jQuery('#city_id').val(cityId);

        // ДОБАВЕНО: Тригерваме update_checkout при промяна на града за "до адрес"
        if(window.shipping_type === 'address') {
            jQuery(document.body).trigger('update_checkout');
        }

        if(window.shipping_type !== 'office' && window.shipping_type !== 'office2')
            return;

        var city = jQuery(`option[value="${cityId}"]`).text();

        city = city.replace('гр. ', '');
        city = city.replace('с. ', '');

        fetchOffices(city);

    });

    jQuery(document.body).on('change', '#billing_address_1, #billing_office, #billing_shipping_type, #billing_street_number, #billing_block', function(e) {
     jQuery(document.body).trigger('update_checkout');
    });

    jQuery(document.body).on('change', 'input[name="payment_method"]', function() {
    var val = this.value;
    var $hidden = jQuery('form.checkout input#speedy_wc_payment_method');
    if (!$hidden.length) {
        jQuery('<input>', { type: 'hidden', id: 'speedy_wc_payment_method', name: 'payment_method', value: val }).appendTo('form.checkout');
    } else {
        $hidden.val(val);
    }
    jQuery(document.body).trigger('update_checkout');
});

    /**
   * When Shipping Method Changes reset Address, City and trigger region change
   */
  jQuery(document.body).on('change', '.shipping_method', function(e) {
    if( window.chosen_shipping != 'econt_shipping' && window.chosen_shipping != 'speedy_shipping' ) return;
    jQuery('#billing_address_1').val('');
    jQuery('#billing_city').empty().select2();
    jQuery('#billing_state').trigger('change');
  });

  tippy('#billing_city', {
    content: 'Моля, пишете само на латиница',
    trigger: 'click',
    placement: 'top'
  });

  tippy('#billing_address_1', {
    content: 'Моля, пишете само на латиница',
    trigger: 'click',
    placement: 'top'
  }); 

  // Fix delivery state label
  setTimeout(function(){
    $('#billing_state_field label span').replaceWith('<abbr class="required" title="">*</abbr>');
  }, 750);
  
  // Checkout shipping handler
  $('#billing_delivery_type').val($('#chosen_method').val());
  
  if ( $.isFunction($.fn.select2) ) {
    $('#billing_delivery_type').select2();
  }
  
  $('#billing_delivery_type').on('change', function(){
    $('input[name="shipping_method\[0\]"]').val([$(this).val()]);
    $('.shipping_method').trigger('change');
    $('#chosen_method').val($(this).val());
    window.chosen_shipping = $(this).val();

    if ( $.isFunction($.fn.select2) ) {
      $('#billing_delivery_type').select2('destroy');
      $('#billing_delivery_type').select2();
    }
  });
  
  $(document.body).on('updated_checkout', function() {
    if($('input[name^="shipping_method"]:checked').val() != $('#billing_delivery_type').val() && typeof $('input[name^="shipping_method"]:checked').val() != 'undefined' ) {
      $('#billing_delivery_type').val($('input[name^="shipping_method"]:checked').val());
      if ( $.isFunction($.fn.select2) ) {
        $('#billing_delivery_type').select2('destroy');
        $('#billing_delivery_type').select2();
      }
    }
    document.querySelectorAll('.woocommerce-Price-amount').forEach(el => {
        const text = el.innerText.trim();
        const match = text.match(/^([\d.,]+)\s*лв\./);
        
        if (match) {
          const bgn = parseFloat(match[1].replace(',', '.'));
          const eur = (bgn / 1.9548).toFixed(2);
          
          // Проверка дали вече е добавено евро, за да не го дублираме
          if (!el.innerText.includes('€')) {
            //el.innerHTML += ` / ${eur} €`;
          }
        }
      });
  });

  //пускаме адрес да е по подразбиране

  

  
});

// Trigger a checkout update when payment method changes
jQuery(document.body).on('change', 'input[name="payment_method"]', function() {
    // optional debug: console.log('Payment method changed to', this.value);
    jQuery(document.body).trigger('update_checkout');
});

jQuery(function($){

    function isForeignCountrySelected() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    return iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO';
}

function stripCyrillicChars(text) {
    return (text || '').replace(/[\u0400-\u04FF]/g, '');
}

var latinAlertLock = false;
function showLatinOnlyAlert() {
    if (latinAlertLock) return;
    latinAlertLock = true;
    alert('Моля, пишете само на латиница');
    setTimeout(function() { latinAlertLock = false; }, 1000);
}

function enforceLatinOnlyOnForeignField($field) {
    if (!isForeignCountrySelected() || !$field.length) return;

    var current = $field.val() || '';
    var cleaned = stripCyrillicChars(current);

    if (cleaned !== current) {
        $field.val(cleaned);
        showLatinOnlyAlert();
    }
}

function enforceLatinNow() {
    enforceLatinOnlyOnForeignField($('#billing_city_text'));
    enforceLatinOnlyOnForeignField($('#billing_address_2'));
    enforceLatinOnlyOnForeignField($('#billing_street'));
    enforceLatinOnlyOnForeignField($('#billing_street2'));
}

// input + paste
$(document.body).on('input', '#billing_city_text, #billing_address_2, #billing_street, #billing_street2', function() {
    enforceLatinOnlyOnForeignField($(this));
});
$(document.body).on('paste', '#billing_city_text, #billing_address_2, #billing_street, #billing_street2', function() {
    var $self = $(this);
    setTimeout(function() { enforceLatinOnlyOnForeignField($self); }, 0);
});

// IME/composition (важно за кирилица layout)
$(document.body).on('compositionend', '#billing_city_text, #billing_address_2, #billing_street, #billing_street2', function() {
    enforceLatinOnlyOnForeignField($(this));
});

// blur/change fallback
$(document.body).on('change blur', '#billing_city_text, #billing_address_2, #billing_street, #billing_street2', function() {
    enforceLatinOnlyOnForeignField($(this));
});

// след Woo re-render
$(document.body).on('updated_checkout', function() {
    enforceLatinNow();
});

// при смяна на държава
$(document.body).on('change', '#billing_country', function() {
    if (!isForeignCountrySelected()) return;
    enforceLatinNow();
});

// initial pass
setTimeout(enforceLatinNow, 0);

// native beforeinput за директно блокиране на кирилица
document.addEventListener('beforeinput', function(e) {
    var t = e.target;
    if (!t || (
            t.id !== 'billing_city_text' &&
            t.id !== 'billing_address_2' &&
            t.id !== 'billing_street' &&
            t.id !== 'billing_street2'
        )) return;
    if (!isForeignCountrySelected()) return;
    if (typeof e.data !== 'string' || e.data === '') return;

    if (/[\u0400-\u04FF]/.test(e.data)) {
        e.preventDefault();
        showLatinOnlyAlert();
    }
}, true);

    function syncForeignPhoneRequired() {
    var iso2 = ($('#billing_country').val() || '').toUpperCase();
    var isForeign = (iso2 !== '' && iso2 !== 'BG' && iso2 !== 'RO');

    var $row = $('#billing_phone_field');
    var $input = $('#billing_phone');
    if (!$row.length || !$input.length) return;

    var $label = $row.find('label');
    if (!$label.data('orig-html')) {
        $label.data('orig-html', $label.html());
    }

    // чистим "optional" и стара required звездичка
    $label.find('.optional').remove();
    $label.find('abbr.required').remove();

  $input.prop('required', true).attr('aria-required', 'true');
$row.addClass('validate-required');

var cleanHtml = ($label.html() || '').replace(/(?:&nbsp;|\s)+$/g, '');
$label.html(cleanHtml + ' <abbr class="required" title="задължително">*</abbr>');
}

function syncForeignPhoneRequiredDelayed() {
    window.clearTimeout(window.__speedyPhoneReqT);
    window.__speedyPhoneReqT = window.setTimeout(syncForeignPhoneRequired, 120);
}

// initial + events
syncForeignPhoneRequiredDelayed();
$(document.body).on('change', '#billing_country', syncForeignPhoneRequiredDelayed);
$(document.body).on('updated_checkout', syncForeignPhoneRequiredDelayed);

     $(document.body).on('updated_checkout ready', function() {
    $('#billing_abroadoffice_field label .optional').remove();
  });

    var ajaxUrl = (typeof shipping_settings !== 'undefined' && shipping_settings.ajax_url)
  ? shipping_settings.ajax_url
  : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
  var $input = $('#billing_abroadoffice');
  var $hidden = $('#billing_abroadoffice_id');
  var $list  = $('#billing_abroadoffice-suggestions');
  var selectedIndex = -1;

function requestAbroadOffices(q, countryIso, countryId) {
    $.post(ajaxUrl, {
        action: 'speedy_get_offices',
        name: q,
        countryIso: countryIso,
        countryId: parseInt(countryId, 10) || 0
    }, function(res){
        if (res && res.success && res.data) {
            var payload = res.data;
            var offices = payload && payload.offices ? payload.offices : payload;
            renderSuggestions(offices);
        } else {
            $list.empty().hide();
        }
    }, 'json').fail(function(err){
        console.error('speedy_get_offices ajax fail', err);
        $list.empty().hide();
    });
}

function renderSuggestions(offices) {
  $list.empty();
  selectedIndex = -1;

  if (!offices || !offices.length) {
     $list.hide();
    //$list.append('<li style="color:#999;padding:3px;">Няма резултати</li>').show();
    return;
  }

  offices.forEach(function(o) {
    var id = o.id || o.officeId || o.identifier || o.code;

    var officeName = o.name || o.description || '';

    var officeAddress = '';
    if (typeof o.address === 'string') {
      officeAddress = o.address;
    } else if (o.address && typeof o.address === 'object') {
      officeAddress =
        o.address.fullAddressString ||
        o.address.fullAddress ||
        o.address.addressString ||
        '';
    }

    // Extract just the city name for the billing_city field
    var cityName = '';
    if (o.address && typeof o.address === 'object') {
      cityName =
        o.address.siteName ||
        o.address.localityCyrName ||
        o.address.localityName ||
        '';
    }

    var displayText = officeName;
    if (officeAddress) {
      displayText = officeName ? (officeName + ', ' + officeAddress) : officeAddress;
    }

    var $li = $('<li>')
      .text(displayText)
      .attr('data-id', id)
      .css({ cursor: 'pointer', padding: '6px' })
      .on('click', function() {
        selectItem(id, displayText, cityName);  // pass cityName as 3rd arg
      });

    $list.append($li);
  });

  $list.show();
}

 function selectItem(id, name, cityName) {
  $input.val(name);
  $hidden.val(id);

  var cityValue = (cityName && cityName.trim() !== '') ? cityName : '';

  if (cityValue) {
    var $cityText = $('#billing_city_text');
    var $cityField = $('#billing_city_field');

   if ($cityText.length) {
$cityText.val(cityValue).trigger('change');
$('#billing-city-suggestions').empty().hide();
$cityText.removeAttr('aria-invalid');
} else if ($('#billing_city').length) {
$('#billing_city').val(cityValue).trigger('change');
}

    if ($cityField.length) {
      $cityField.removeClass('woocommerce-invalid').addClass('woocommerce-validated');
    }
  }

  if ($('#billing_office').length) {
    var officeVal = String(id || '');
    if (officeVal !== '') {
      if ($('#billing_office option[value="' + officeVal + '"]').length === 0) {
        $('#billing_office').append($('<option>', { value: officeVal, text: name }));
      }
      $('#billing_office').val(officeVal).trigger('change');
            $list.empty().hide();
            selectedIndex = -1;
            return;
    }
  }

  $list.empty().hide();
  selectedIndex = -1;
  jQuery(document.body).trigger('update_checkout');
}

  $input.on('input', function(){
    var q = $(this).val();
    if (!q || q.length < 2) { $list.empty().hide(); return; }

        var countryIso = ($('#billing_country').val() || '').toUpperCase();
        var hiddenCountryId = parseInt($('#speedy_country_id').val() || '0', 10) || 0;

        if (hiddenCountryId > 0) {
            requestAbroadOffices(q, countryIso, hiddenCountryId);
            return;
        }

        if (typeof window.speedyGetCountryId === 'function' && countryIso !== '' && countryIso !== 'BG') {
            window.speedyGetCountryId(countryIso, function(cidRaw) {
                var cid = normalizeCountryIdValue(cidRaw);
                if (cid > 0) {
                    $('#speedy_country_id').val(String(cid));
                }
                requestAbroadOffices(q, countryIso, cid);
            });
            return;
        }

        requestAbroadOffices(q, countryIso, hiddenCountryId);
  
});
 $input.on('keydown', function(e){
  var $items = $list.find('li');

  if (e.key === 'Enter') {
    // Никога да не submit-ва checkout формата от това поле
    e.preventDefault();

    if (!$list.is(':visible') || !$items.length) return;

    // Ако няма маркиран ред -> избираме първия
    if (selectedIndex < 0) {
      selectedIndex = 0;
      highlight($items);
    }

    // Критично: trigger click, за да мине през click handler-а
    // и да подаде cityName към selectItem(...)
    $items.eq(selectedIndex).trigger('click');
    return;
  }

  if (!$items.length) return;

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    selectedIndex = (selectedIndex + 1) % $items.length;
    highlight($items);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
    highlight($items);
  }
});

jQuery(document).ready(function($) {
  $(document.body).on('change', 'input[name="speedy_selected_service_choice"]', function() {
    var selectedServiceId = String($(this).val() || '');
    var $hidden = $('#speedy_selected_service_id');

    if (!$hidden.length) {
      $hidden = $('<input>', {
        type: 'hidden',
        id: 'speedy_selected_service_id',
        name: 'speedy_selected_service_id',
        value: selectedServiceId
      }).appendTo('form.checkout');
    } else {
      $hidden.val(selectedServiceId);
    }

    $(document.body).trigger('update_checkout', { update_shipping_method: true });
  });
});

  function highlight($items){
    $items.css({'background':'','color':''});
    if (selectedIndex >= 0) $items.eq(selectedIndex).css({'background':'#007BFF','color':'#fff'});
  }

  $(document).on('click', function(e){
    if (!$(e.target).closest('#billing_abroadoffice, #billing_abroadoffice-suggestions').length) {
      $list.empty().hide();
      selectedIndex = -1;
    }
  });
});