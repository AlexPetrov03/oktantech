jQuery(document.body).ready(function() {
  function speedyReindexCurrencyRows() {
jQuery('#speedy-currency-rates-table tbody tr').each(function(index) {
jQuery(this).attr('id', 'currency_row_' + index);
jQuery(this).find('.currency-iso')
.attr('name', 'woocommerce_speedy_shipping_method_currency_rate[' + index + '][iso_code]');
jQuery(this).find('.currency-rate')
.attr('name', 'woocommerce_speedy_shipping_method_currency_rate[' + index + '][rate]');
});
}

jQuery(document.body).on('click', '#speedy-add-currency-rate', function() {
var index = jQuery('#speedy-currency-rates-table tbody tr').length;

var rowHtml = ''
    + '<tr id="currency_row_' + index + '">'
    + '  <td><input type="text" class="woocommerce_speedy_shipping_method_currency_rate currency-iso" '
    + '      name="woocommerce_speedy_shipping_method_currency_rate[' + index + '][iso_code]" placeholder="ISO Code" maxlength="3"></td>'
    + '  <td><input type="text" class="woocommerce_speedy_shipping_method_currency_rate currency-rate" '
    + '      name="woocommerce_speedy_shipping_method_currency_rate[' + index + '][rate]" placeholder="Rate"></td>'
    + '  <td><button type="button" class="button remove_currency">Remove</button></td>'
    + '</tr>';

jQuery('#speedy-currency-rates-table tbody').append(rowHtml);
});

jQuery(document.body).on('click', '.remove_currency', function() {
jQuery(this).closest('tr').remove();
speedyReindexCurrencyRows();
});


    jQuery('input[type="number"]').attr('step', '0.01');
    
    jQuery('#woocommerce_speedy_shipping_fixed_shipping').change(function(e) {
        jQuery('.fixed-shipping-office, .fixed-shipping-address').slideToggle();
    });

    jQuery('#woocommerce_speedy_shipping_free_shipping').change(function(e) {
        jQuery('.free-shipping-office, .free-shipping-address').slideToggle();
    });

    jQuery(document.body).on('click', '.regenerate-data-speedy', function(e) {
        e.preventDefault();

        displayLoader();
        jQuery.ajax({
            url: speedy.ajax_url,
            method: 'POST',
            data: {
                action: 'speedy_regenerate'
            },
           success: function(res) {
            hideLoader();
            if (res.success) {
                alert('Операцията завърши успешно');
                
                // Обновяване на стойността на полето, ако `clientId` е върнато
                if (res.data && res.data.clientId) {
                    jQuery('#woocommerce_speedy_shipping_sender_id').val(res.data.clientId);
                }
            } else {
                alert('Операцията е успешна!');
            }
        },
            error: function(e) {
                hideLoader();
                alert('Грешка, моля свържете се с техническа поддръжка');
            }

        });
    });

    jQuery('#woocommerce_speedy_shipping_sender_city').parent().append('<div id="sender_city_autocomplete"></div>');
    jQuery('#woocommerce_speedy_shipping_sender_city').wrap('<div class="ui-widget"></div>');
  
    jQuery('#woocommerce_speedy_shipping_sender_city').autocomplete({
        appendTo: '#sender_city_autocomplete',
        source: function( request, response ) {
            jQuery.ajax({
              url: speedy.ajax_url,
              method: 'POST',
              data: {
                action: 'speedy_city_autocomplete',
                term: request.term
              },
              success: function( res ) {
                response( res.data );
              }
            });
        },
        minLength: 3,
        select: function( event, ui ) {
          event.preventDefault();
          jQuery('#woocommerce_speedy_shipping_sender_city').val(ui.item.label);
          fetchOffices(ui.item.label);
        },
        open: function() {
          jQuery( this ).removeClass( "ui-corner-all" ).addClass( "ui-corner-top" );
        },
        close: function() {
          jQuery( this ).removeClass( "ui-corner-top" ).addClass( "ui-corner-all" );
        }
    });
});

function fetchOffices(city) {
  var cityName = city.split(' ').slice(0, city.split(' ').length - 1).join(' ');

  jQuery.ajax({
      url: speedy.ajax_url,
      method: 'POST',
      data: {
          action: 'speedy_get_offices_by_city',
          city: cityName
      },
      success: function(res) {
          if(!res.success || res.data.length === 0) {
              alert('Не бяха намерени офиси в този град')
              return;
          }

          var offices = res.data;
          var $officeField = jQuery('#woocommerce_speedy_shipping_sender_office');
          $officeField.empty();
          for(var i = 0; i < offices.length; i++) {
              $officeField.append('<option value="' + offices[i].id + '">' + offices[i].address + '</option>');
          }
      }
  });
}

function displayLoader() {
    var html = '<div id="loader-back"><div class="lds-roller"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div></div>';
    var css  = `
    #loader-back {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: rgba(0,0,0,0.5);
        z-index: 1000;
    }
    
    .lds-roller {
        display: inline-block;
        position: relative;
        width: 80px;
        height: 80px;
      }
      .lds-roller div {
        animation: lds-roller 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        transform-origin: 40px 40px;
      }
      .lds-roller div:after {
        content: " ";
        display: block;
        position: absolute;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #fff;
        margin: -4px 0 0 -4px;
      }
      .lds-roller div:nth-child(1) {
        animation-delay: -0.036s;
      }
      .lds-roller div:nth-child(1):after {
        top: 63px;
        left: 63px;
      }
      .lds-roller div:nth-child(2) {
        animation-delay: -0.072s;
      }
      .lds-roller div:nth-child(2):after {
        top: 68px;
        left: 56px;
      }
      .lds-roller div:nth-child(3) {
        animation-delay: -0.108s;
      }
      .lds-roller div:nth-child(3):after {
        top: 71px;
        left: 48px;
      }
      .lds-roller div:nth-child(4) {
        animation-delay: -0.144s;
      }
      .lds-roller div:nth-child(4):after {
        top: 72px;
        left: 40px;
      }
      .lds-roller div:nth-child(5) {
        animation-delay: -0.18s;
      }
      .lds-roller div:nth-child(5):after {
        top: 71px;
        left: 32px;
      }
      .lds-roller div:nth-child(6) {
        animation-delay: -0.216s;
      }
      .lds-roller div:nth-child(6):after {
        top: 68px;
        left: 24px;
      }
      .lds-roller div:nth-child(7) {
        animation-delay: -0.252s;
      }
      .lds-roller div:nth-child(7):after {
        top: 63px;
        left: 17px;
      }
      .lds-roller div:nth-child(8) {
        animation-delay: -0.288s;
      }
      .lds-roller div:nth-child(8):after {
        top: 56px;
        left: 12px;
      }
      @keyframes lds-roller {
        0% {
          transform: rotate(0deg);
        }
        100% {
          transform: rotate(360deg);
        }
      }`;
    
    jQuery('body').append(html);
    jQuery('body').append(`<style id="loader-style">${css}</style>`);
}

function hideLoader() {
    jQuery('#loader-back').remove();
    jQuery('#loader-style').remove();
}
jQuery(document).ready(function() {
  jQuery('head').append('<style>#speedy-currency-rates-table tr, #speedy-currency-rates-table thead tr, #speedy-currency-rates-table tbody tr { display: table-row !important; } #speedy-currency-rates-table th, #speedy-currency-rates-table td { display: table-cell !important; }</style>');
});