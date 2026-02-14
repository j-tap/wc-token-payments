( function () {
	'use strict';

	// Guard: exit silently if WooCommerce Blocks API is not available
	if (
		! window.wc ||
		! window.wc.blocksRegistry ||
		! window.wc.blocksRegistry.registerPaymentMethod ||
		! window.wc.wcSettings
	) {
		return;
	}

	var registerPaymentMethod = window.wc.blocksRegistry.registerPaymentMethod;
	var createElement         = window.wp.element.createElement;
	var decodeEntities        = window.wp.htmlEntities.decodeEntities;
	var getSetting            = window.wc.wcSettings.getSetting;

	var data  = getSetting( 'wctk_tokens_data', {} );
	var title = decodeEntities( data.title || 'Pay with Tokens' );

	/**
	 * Content shown when this payment method is selected.
	 */
	var Content = function ( props ) {
		var balance      = data.balance || 0;
		var rate         = data.rate || 1;
		var balanceLabel = data.balanceLabel || 'Token balance:';
		var billing      = props.billing || {};
		var cartTotal    = billing.cartTotal || {};
		var totalValue   = cartTotal.value || 0;
		// cartTotal.value is in minor units (cents)
		var total        = totalValue / 100;
		var needed       = rate > 0 && total > 0 ? Math.ceil( total / rate ) : 0;

		var children = [
			createElement(
				'p',
				{ className: 'wctk-gateway-info__balance', key: 'bal' },
				balanceLabel + ' ',
				createElement( 'strong', { className: 'wctk-gateway-info__balance-value' }, balance )
			),
		];

		if ( needed > 0 ) {
			children.push(
				createElement(
					'p',
					{ className: 'wctk-gateway-info__cost', key: 'cost' },
					'Tokens needed: ' + needed
				)
			);

			if ( balance < needed ) {
				children.push(
					createElement(
						'p',
						{
							className: 'wctk-gateway-info__warning',
							style: { color: '#b32d2e' },
							key: 'warn',
						},
						'Not enough tokens. Need ' + needed + ', you have ' + balance + '.'
					)
				);
			}
		}

		return createElement( 'div', { className: 'wctk-gateway-info' }, children );
	};

	/**
	 * Label in the payment method list.
	 */
	var Label = function () {
		return createElement( 'span', null, title );
	};

	/**
	 * Only show the gateway when user is logged in and cart is NOT a top-up.
	 */
	var canMakePayment = function () {
		return !!data.loggedIn && !data.isTopup;
	};

	registerPaymentMethod( {
		name: 'wctk_tokens',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: canMakePayment,
		ariaLabel: title,
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )();
