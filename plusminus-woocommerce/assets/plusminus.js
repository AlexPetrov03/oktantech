jQuery(document).ready(function ($) {
    if ($('#plusminusWooTestBtn').length) {
        $('#plusminusWooTestBtn').on('click', function (e) {
            e.preventDefault();

            let nonce = $(this).data('nonce') || '';
            $('.plusminusWooTestSpinner').addClass('is-active');
            $('#plusminusWooTestBtn').prop('disabled', true);
            $.ajax(
                {
                    url: pmWooHelper.ajaxurl,
                    data: {
                        'nonce': nonce,
                        'action': 'pmwoo_test_connection'
                    },
                    success: function (result) {
                        $('.plusminusWooTestSpinner').removeClass('is-active');
                        $('#plusminusWooTestBtn').prop('disabled', false);
                        console.log(result);
                        alert(result.message);
                    },
                    error: function (xhr, status, error) {
                        alert(xhr.responseText);
                    }
                });
        });
    }

    if ($('#plusminusWooUpdateBtn').length) {
        $('#plusminusWooUpdateBtn').on('click', function (e) {
            e.preventDefault();

            if (!confirm(pmWooHelper.confirm_update)) return;

            let nonce = $(this).data('nonce') || '';
            $('.plusminusWooUpdateSpinner').addClass('is-active');
            $('#plusminusWooUpdateBtn').prop('disabled', true);
            $.ajax(
                {
                    url: pmWooHelper.ajaxurl,
                    data: {
                        'nonce': nonce,
                        'action': 'pmwoo_manual_update'
                    },
                    success: function (result) {
                        $('.plusminusWooUpdateSpinner').removeClass('is-active');
                        $('#plusminusWooUpdateBtn').prop('disabled', false);
                        console.log(result);
                        alert(result.message);
                    },
                    error: function (xhr, status, error) {
                        alert(xhr.responseText);
                    }
                });
        });
    }

    if ($('#plusminusWooImportBtn').length) {
        $('#plusminusWooImportBtn').on('click', function (e) {
            e.preventDefault();

            if (!confirm(pmWooHelper.confirm_import)) return;

            let nonce = $(this).data('nonce') || '';
            $('.plusminusWooImportSpinner').addClass('is-active');
            $('#plusminusWooImportBtn').prop('disabled', true);
            $.ajax(
                {
                    url: pmWooHelper.ajaxurl,
                    data: {
                        'nonce': nonce,
                        'action': 'pmwoo_import_products'
                    },
                    success: function (result) {
                        $('.plusminusWooImportSpinner').removeClass('is-active');
                        $('#plusminusWooImportBtn').prop('disabled', false);
                        console.log(result);
                        alert(result.message);
                    },
                    error: function (xhr, status, error) {
                        alert(xhr.responseText);
                    }
                });
        });
    }

    if ($('#pmWooAddDocBtn').length) {
        $('#pmWooAddDocBtn').on('click', function (e) {
            e.preventDefault();

            let docDetails = prompt($(this).data('message') || "Enter Plus Minus document");
            if (docDetails != '') {
                let nonce = $(this).data('nonce') || '',
                    order_id = $(this).data('order-id') || '';

                $('.pmWooOrderSpinner').addClass('is-active');
                $('#pmWooAddDocBtn').prop('disabled', true);
                $('#pmWooAddDocBtn').css('opacity', '0.5');
                $.ajax(
                    {
                        url: pmWooHelper.ajaxurl,
                        data: {
                            'nonce': nonce,
                            'order': order_id,
                            'pm_doc': docDetails,
                            'action': 'pmwoo_order_add_pmdoc'
                        },
                        success: function (result) {
                            $('.pmWooOrderSpinner').removeClass('is-active');
                            $('#pmWooAddDocBtn').prop('disabled', false);
                            $('#pmWooAddDocBtn').css('opacity', '1');
                            console.log(result);

                            if (result.code) {
                                alert(result.message);
                                if (result.code == '200') window.location.reload();
                            }

                        },
                        error: function (xhr, status, error) {
                            alert(xhr.responseText);
                        }
                    });
            }
        });
    }

    if ($('#pmWooRemoveDocBtn').length) {
        $('#pmWooRemoveDocBtn').on('click', function (e) {
            e.preventDefault();

            if (!confirm($(this).data('message') || 'Please confirm that you wish to remove the Plus Minus document')) return;

            let nonce = $(this).data('nonce') || '',
                order_id = $(this).data('order-id') || '';

            $('.pmWooOrderSpinner').addClass('is-active');
            $('#pmWooRemoveDocBtn').prop('disabled', true);
            $('#pmWooRemoveDocBtn').css('opacity', '0.5');
            $.ajax(
                {
                    url: pmWooHelper.ajaxurl,
                    data: {
                        'nonce': nonce,
                        'order': order_id,
                        'action': 'pmwoo_order_clear_pmdocF'
                    },
                    success: function (result) {
                        $('.pmWooOrderSpinner').removeClass('is-active');
                        $('#pmWooRemoveDocBtn').prop('disabled', false);
                        $('#pmWooRemoveDocBtn').css('opacity', '1');
                        console.log(result);

                        if (result.code) {
                            alert(result.message);
                            if (result.code == '200') window.location.reload();
                        }

                    },
                    error: function (xhr, status, error) {
                        alert(xhr.responseText);
                    }
                });

        });
    }
});