/**
 * Emporiqa cart handler for in-chat cart operations (Sylius).
 *
 * Provides window.EmporiqaCartHandler which the Emporiqa embed script calls
 * when a customer requests cart operations through the chat widget.
 *
 * Uses Emporiqa cart API routes (/emporiqa/api/cart/*).
 * Includes CSRF token for authenticated users via X-CSRF-Token header.
 */
(function () {
  'use strict';

  var csrfToken = null;

  /**
   * Fetches CSRF token from the server. Cached after first fetch.
   * Retries are handled by resetting csrfToken to null on 403.
   */
  async function getCsrfToken() {
    if (csrfToken !== null) return csrfToken;
    try {
      var response = await fetch('/emporiqa/api/csrf-token', { credentials: 'same-origin' });
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

  /**
   * Extracts the numeric ID from a prefixed identifier.
   * The Emporiqa SaaS sends IDs like "product-123" or "variation-456".
   */
  function extractNumericId(id) {
    var str = String(id);
    var match = str.match(/(\d+)$/);
    return match ? match[1] : str;
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

  async function postJson(url, body) {
    var token = await getCsrfToken();
    var headers = { 'Content-Type': 'application/json' };
    if (token) {
      headers['X-CSRF-Token'] = token;
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
        variation_id: extractNumericId(entityId),
        quantity: Number(items[i].quantity) || 1
      });
    }

    var response = await postJson('/emporiqa/api/cart/add', { items: payload });
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

    var response = await postJson('/emporiqa/api/cart/update', {
      variation_id: extractNumericId(variationId),
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

    var response = await postJson('/emporiqa/api/cart/remove', {
      variation_id: extractNumericId(variationId)
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
    var response = await postJson('/emporiqa/api/cart/clear');
    if (!response.ok) {
      return buildResponse(false, { error: 'Failed to clear cart' });
    }
    return parseServerResponse(await response.json());
  }

  async function viewCart() {
    var response = await fetch('/emporiqa/api/cart', { credentials: 'same-origin' });
    if (!response.ok) {
      return buildResponse(false, { error: 'Failed to load cart' });
    }
    return parseServerResponse(await response.json());
  }

  async function goToCheckout() {
    var response = await fetch('/emporiqa/api/cart/checkout-url', { credentials: 'same-origin' });
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
