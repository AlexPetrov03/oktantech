jQuery(document.body).ready(function() {
  var shipping_method = jQuery('#shipping-method').val();

  jQuery('#fast-order-form #do-order').hide();

  jQuery('#shipping-method').change(function() {
      shipping_method = jQuery(this).val();
      jQuery('#region').trigger('change');

      jQuery('#fast-order-form #do-order').hide();
  });

  jQuery('#shipping-type').change(function() {
      var val = jQuery(this).val();

      if(val === 'office') {
          jQuery('.office-wrapper').show();
          jQuery('.street-wrapper').hide();
      } else {
          jQuery('.office-wrapper').hide();
          jQuery('.street-wrapper').show();
      }

      jQuery('input[name=city]').val('');

      jQuery('#fast-order-form #do-order').hide();
  });

  jQuery('#region').change(function() {
    var region = jQuery(this).val();
    fetchCities(region, jQuery('#shipping-method').val());
    resetOffices();

    jQuery('#fast-order-form #do-order').hide();
  });

  jQuery('#city').change(function() {
    var city = jQuery(this).val();
    fetchOffices(city, jQuery('#shipping-method').val());
    jQuery('#city_id').val(jQuery(this).val());

    jQuery('#fast-order-form #do-order').hide();
  });

  formToggle();

  jQuery('.calculate-shipping').click(function() {
      jQuery('input[name="inner_action"]').val('calculate');
  });

  jQuery('#do-order').click(function() {
      jQuery('input[name="inner_action"]').val('order');
  });

  jQuery('#fast-order-form').on('submit', onSubmit);

  tippy('input[name=city]', {
    content: 'Моля пишете на кирилица',
    trigger: 'click',
    placement: 'top'
  });

  tippy('input[name=address]', {
    content: 'Моля пишете на кирилица',
    trigger: 'click',
    placement: 'top'
  });
  
});

function fetchCities(region, method) {
  var action = method === 'econt_shipping' ? 'get_cities_by_region' : 'speedy_get_cities_by_region';

  jQuery.ajax({
    url: jQuery('#fast-order-form').attr('action'),
    method: 'POST',
    data: {
      action: action,
      region: region
    },
    success: function(res) {
      var select = jQuery('#city'); 
      select.empty();
      select.append(`<option selected disabled>Изберете населено място</option>`);
      for(var i = 0; i < res.data.length; i++) {
        var city = res.data[i];
        select.append(`<option value="${city.id}">${city.type} ${city.name}</option>`);
      }

      select.select2();
    }
  });
}

function fetchOffices(city, method) {
  var action = method === 'econt_shipping' ? 'get_offices_by_city_id' : 'speedy_get_offices_by_city_id';

  jQuery.ajax({
    url: jQuery('#fast-order-form').attr('action'),
    method: 'POST',
    data: {
      action: action,
      city: city
    },
    success: function(res) {
      var $select = jQuery('#office');
      $select.show();
      $select.empty();
      jQuery('#no-offices-warning').remove();
    
      if(res.data.length === 0) {
        if ($select.data('select2')) {
          $select.select2('destroy');
        }

        $select.hide();
        $select.get(0).insertAdjacentHTML('afterEnd', "<p style='color: #e74c3c; font-weight: 600' id='no-offices-warning'>В това населено място няма офис. Изберете опция 'Доставка до адрес'.</p>");
        return;
      }

      $select.append('<option selected disabled>Изберете офис</option>');
      for(var i = 0; i < res.data.length; i++) {
        var office = res.data[i];

        var prefix = '';
        office_name = encodeURI(office.name);
        if (office_name.indexOf(encodeURI("АВТОМАТ")) >= 0) var prefix = '[АВТОМАТ] ';
        if (office_name.indexOf(encodeURI("Еконтомат")) >= 0) var prefix = '[ЕКОНТОМАТ] ';

        $select.append('<option value="' + office.id + '">' + prefix + office.address + '</option>');
      }

      $select.select2();
    },
    error: function() {
      alert('Няма офис в този град');
    }
  });
}

function resetOffices() {
var $select = jQuery('#office');
$select.empty();
$select.select2();
}

function formToggle() {
  jQuery('.order-form-toggler').click(function(e) {
      jQuery(this).toggleClass('opened');
      e.preventDefault();
      jQuery('.order-form').slideToggle();
  });
}

function onSubmit(e) {
  e.preventDefault();

  if(jQuery('input[name="variation_id"]').length) {
    jQuery('input[name="prod_var_id"]').val(jQuery('input[name="variation_id"]').val());
  }
  
  var data = jQuery(this).serializeArray();

  jQuery('.order-form .error-box').slideUp();

  toggleLoader(true);
  
  if(actionType(data) === 'calculate') {
      onCalculate(this);
  } else if(actionType(data) === 'order') {
      onOrder(this);
  }
}

function onCalculate(form) {
  var shippingMethod = jQuery('#shipping-method').val();

  jQuery('#fast-order-form #do-order').hide();

  var action = shippingMethod === 'econt_shipping' ? 'econt_calculate_shipping' : 'speedy_calculate_shipping';

  jQuery('#fast-order-form input[name=action]').val(action);
  jQuery('.shipping-price hidden').slideUp();

  jQuery.ajax({
      url: jQuery('#fast-order-form').attr('action'),
      type: 'POST',
      data: jQuery(form).serialize(),
      success:function(response) {
          jQuery('.order-price').slideDown();
          jQuery('.order-price-value').html(response.data.order + 'лв');
          jQuery('.shipping-price').slideDown();
          jQuery('.shipping-price-value').html(response.data.price + 'лв');
          toggleLoader(false);
          jQuery('#fast-order-form #do-order').show();
      },
      error: function(err) {
          var error = err.responseJSON.data[0];
          jQuery('.order-form .error-box').html(error);
          jQuery('.order-form .error-box').slideDown();
          toggleLoader(false);
      }
  });
}

function onOrder(form) {
  var shippingMethod = jQuery('#shipping-method').val();

  var action = shippingMethod === 'econt_shipping' ? 'econt_fast_order' : 'speedy_fast_order';

  jQuery('#fast-order-form input[name=action]').val(action);

  jQuery.ajax({
      url: jQuery('#fast-order-form').attr('action'),
      type: 'POST',
      data: jQuery(form).serialize(),
      success:function(response) {
          toggleLoader(false);
          var url = response.data.url;
          window.location.href = url;
      },
      error: function(err) {
          var error = err.responseJSON.data[0];
          jQuery('.order-form .error-box').html(error);
          jQuery('.order-form .error-box').slideDown();
          toggleLoader(false);
      }
  });
}

function actionType(data) {
  for(var i = 0; i < data.length; i++) {
      if(data[i].name === 'inner_action')
          return data[i].value;
  }

  return '';
}

function toggleLoader(turnon) {
  if(turnon)
      jQuery('.fast-order-loader').css('display', 'flex');
  else jQuery('.fast-order-loader').hide();
}