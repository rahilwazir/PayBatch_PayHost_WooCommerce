jQuery(function (){
	jQuery.ajaxSetup({
		complete: function (xhr, textStatus){
			var responseText = xhr.responseText;
			var result = JSON.parse(xhr.responseText);
			if(result.hasOwnProperty('cancel_url') && result.cancel_url.length > 0){
				window.top.location.href = result.cancel_url;
				return;
			}
			if(result.PAY_REQUEST_ID){
				jQuery('.woocommerce-error').remove();
				initPayPopup(result);
				return false;
			}
			return;
		}
	});
	jQuery(document).ajaxComplete(function (){
		if(jQuery('body').hasClass('woocommerce-checkout') || jQuery('body').hasClass('woocommerce-cart')){
			jQuery('html, body').stop();
		}
	});
});

function initPayPopup(result){
	jQuery("body").append("<div id='payPopup'></div>");
	jQuery("#payPopup").append("<div id='payPopupContent'></div>");
	var formHtml = "<form target='myIframe' name='payhostpaybatch_checkout' id='payhostpaybatch_checkout' action='" + result.processUrl + "' method='post'><input type='hidden' name='PAY_REQUEST_ID' value='" + result.PAY_REQUEST_ID + "' size='200'><input type='hidden' name='CHECKSUM' value='" + result.CHECKSUM + "' size='200'></form><iframe id='payPopupFrame' name='myIframe'  src='#' ></iframe><script type='text/javascript'>document.getElementById('payhostpaybatch_checkout').submit();</script>"
	jQuery("#payPopupContent").append(formHtml);
}

jQuery(document).on('submit', 'form#order_review', function (e){
	jQuery("#place_order").attr("disabled", "disabled");
	var contine = true;
	if(jQuery('#terms').length){
		if(!jQuery("#terms").is(":checked") == true){
			contine = false;
		}
		;
	}
	if(contine && jQuery('#payment_method_payhostpaybatch').length && jQuery("#payment_method_payhostpaybatch").is(":checked") == true){
		e.preventDefault();
		jQuery.ajax({
			'url':      wc_add_to_cart_params.ajax_url,
			'type':     'POST',
			'dataType': 'json',
			'data':     {
				'action':   'order_pay_payment',
				'order_id': payhostpaybatch_checkout_js.order_id
			},
			'async':    false
		}).complete(function (result){
			var result = JSON.parse(result);
			initPayPopup(result);
		});
	}
});