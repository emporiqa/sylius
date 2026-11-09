/**
 * Emporiqa cart handler for in-chat cart operations (Sylius).
 *
 * Provides window.EmporiqaCartHandler which the Emporiqa embed script calls
 * when a customer requests cart operations through the chat widget.
 *
 * Uses Emporiqa cart API routes (/emporiqa/api/cart/*), at the URLs the
 * server puts in this script tag's data-emporiqa-config, so a shop under a
 * sub-path works and the page needs no inline script.
 * Includes CSRF token for authenticated users via X-CSRF-Token header.
 */
(function () {
  'use strict';

  // Read at the top level: document.currentScript is null in callbacks.
  // Still exposed as window.emporiqaConfig for shop code that reads it; a
  // page cached from before 1.12.0 sets it inline instead.
  var script = document.currentScript;
  var attr = script && script.getAttribute('data-emporiqa-config');
  if (attr) {
    try {
      window.emporiqaConfig = JSON.parse(attr);
    } catch (e) {
      // Unreadable config: the defaults below still reach the cart API.
    }
  }
  var config = window.emporiqaConfig || {};
  var cartUrl = config.cartUrl || '/emporiqa/api/cart';
  var csrfUrl = config.csrfUrl || '/emporiqa/api/csrf-token';

  var csrfToken = null;

  /**
   * Fetches CSRF token from the server. Cached after first fetch.
   * Retries are handled by resetting csrfToken to null on 403.
   */
  async function getCsrfToken() {
    if (csrfToken !== null) return csrfToken;
    try {
      var response = await fetch(csrfUrl, { credentials: 'same-origin' });
      if (response.ok) {
        var data = await response.json();
        csrfToken = data.token || '';
      } else {
        csrfToken = '';
      }
    } catch (e) {
      csrfToken = '';
    }
    return csrfToken;
  }

  function buildResponse(success, extras) {
    return Object.assign({
      success: success,
      error: null,
      checkoutUrl: null,
      cart: null
    }, extras || {});
  }

  function parseServerResponse(data) {
    var cart = null;
    if (data.cart) {
      cart = {
        items: (data.cart.items || []).map(function (item) {
          return {
            product_id: item.product_id ? String(item.product_id) : null,
            variation_id: item.variation_id ? String(item.variation_id) : null,
            name: item.name || '',
            quantity: item.quantity || 0,
            unit_price: item.unit_price || 0,
            image_url: item.image_url || null,
            product_url: item.product_url || null
          };
        }),
        item_count: data.cart.item_count || 0,
        total: data.cart.total || 0,
        currency: data.cart.currency || ''
      };
    }

    return buildResponse(data.success, {
      checkoutUrl: data.checkoutUrl || null,
      cart: cart,
      error: data.success ? null : (data.error || data.message || null)
    });
  }

  function getLocale() {
    return config.language || '';
  }

  async function postJson(url, body) {
    var token = await getCsrfToken();
    var locale = getLocale();
    var headers = { 'Content-Type': 'application/json' };
    if (token) {
      headers['X-CSRF-Token'] = token;
    }
    if (locale) {
      headers['X-Locale'] = locale;
    }

    var response = await fetch(url, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });

    // Retry once with a fresh token on 403 (stale CSRF)
    if (response.status === 403) {
      csrfToken = null;
      token = await getCsrfToken();
      headers = { 'Content-Type': 'application/json' };
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
      response = await fetch(url, {
        method: 'POST',
        headers: headers,
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined
      });
    }

    return response;
  }

  async function addToCart(items) {
    if (!items || !items.length) {
      return buildResponse(false, { error: 'No items provided' });
    }

    var payload = [];
    for (var i = 0; i < items.length; i++) {
      var entityId = items[i].variation_id || items[i].product_id;
      if (!entityId) {
        return buildResponse(false, { error: 'Item missing variation_id or product_id' });
      }
      payload.push({
        variation_id: String(entityId),
        quantity: Number(items[i].quantity) || 1
      });
    }

    var response = await postJson(cartUrl + '/add', { items: payload });
    if (!response.ok) {
      var errorText = '';
      try {
        var errorBody = await response.json();
        errorText = errorBody.error || errorBody.message || response.statusText;
      } catch (e) {
        errorText = response.statusText || 'Unknown error';
      }
      return buildResponse(false, { error: 'Failed to add item to cart: ' + errorText });
    }

    return parseServerResponse(await response.json());
  }

  async function updateCartItem(items) {
    if (!items || !items.length) {
      return buildResponse(false, { error: 'No items provided' });
    }

    var targetItem = items[0];
    var variationId = targetItem.variation_id || targetItem.product_id;
    if (!variationId) {
      return buildResponse(false, { error: 'Item missing variation_id or product_id' });
    }

    var response = await postJson(cartUrl + '/update', {
      variation_id: String(variationId),
      quantity: Number(targetItem.quantity) || 1
    });

    if (!response.ok) {
      var errorText = '';
      try {
        var errorBody = await response.json();
        errorText = errorBody.error || errorBody.message || response.statusText;
      } catch (e) {
        errorText = response.statusText || 'Unknown error';
      }
      return buildResponse(false, { error: 'Failed to update item: ' + errorText });
    }

    return parseServerResponse(await response.json());
  }

  async function removeFromCart(items) {
    if (!items || !items.length) {
      return buildResponse(false, { error: 'No items provided' });
    }

    var targetItem = items[0];
    var variationId = targetItem.variation_id || targetItem.product_id;
    if (!variationId) {
      return buildResponse(false, { error: 'Item missing variation_id or product_id' });
    }

    var response = await postJson(cartUrl + '/remove', {
      variation_id: String(variationId)
    });

    if (!response.ok) {
      var errorText = '';
      try {
        var errorBody = await response.json();
        errorText = errorBody.error || errorBody.message || response.statusText;
      } catch (e) {
        errorText = response.statusText || 'Unknown error';
      }
      return buildResponse(false, { error: 'Failed to remove item: ' + errorText });
    }

    return parseServerResponse(await response.json());
  }

  async function clearCart() {
    var response = await postJson(cartUrl + '/clear');
    if (!response.ok) {
      return buildResponse(false, { error: 'Failed to clear cart' });
    }
    return parseServerResponse(await response.json());
  }

  async function viewCart() {
    var locale = getLocale();
    var fetchHeaders = {};
    if (locale) {
      fetchHeaders['X-Locale'] = locale;
    }
    var response = await fetch(cartUrl, { credentials: 'same-origin', headers: fetchHeaders });
    if (!response.ok) {
      return buildResponse(false, { error: 'Failed to load cart' });
    }
    return parseServerResponse(await response.json());
  }

  async function goToCheckout() {
    var locale = getLocale();
    var fetchHeaders = {};
    if (locale) {
      fetchHeaders['X-Locale'] = locale;
    }
    var response = await fetch(cartUrl + '/checkout-url', { credentials: 'same-origin', headers: fetchHeaders });
    if (!response.ok) {
      return buildResponse(false, { error: 'Cart is empty' });
    }
    var result = parseServerResponse(await response.json());
    if (result.success && result.checkoutUrl) {
      var url = result.checkoutUrl;
      if (url.charAt(0) === '/' || url.indexOf(window.location.origin) === 0) {
        window.location.href = url;
      }
    }
    return result;
  }

  window.EmporiqaCartHandler = async function (params) {
    try {
      if (!params || typeof params.action !== 'string') {
        return buildResponse(false, { error: 'Invalid parameters' });
      }
      var action = params.action;
      var items = params.items;
      switch (action) {
        case 'add':
          return await addToCart(items);
        case 'update':
          return await updateCartItem(items);
        case 'remove':
          return await removeFromCart(items);
        case 'clear':
          return await clearCart();
        case 'view':
          return await viewCart();
        case 'checkout':
          return await goToCheckout();
        default:
          return buildResponse(false, { error: 'Unknown action: ' + action });
      }
    } catch (error) {
      return buildResponse(false, { error: error.message || 'Cart operation failed' });
    }
  };
})();
