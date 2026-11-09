/**
 * Emporiqa customer token (Sylius).
 *
 * Hands the signed-in shopper's token to the Emporiqa widget without ever
 * putting it in the page HTML or the widget URL (page caches would serve it
 * to someone else, and URLs end up in server logs), and without broadcasting
 * it to the other scripts on the page.
 *
 * When the chat opens, embed.js creates a MessageChannel and asks with
 *   window.postMessage({type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port'}, location.origin, [port])
 * and this script answers ONLY on that port: first
 *   {type: 'EMPORIQA_TOKEN_PENDING'}
 * so the widget holds the shopper's first message for the token, then
 *   {type: 'EMPORIQA_CUSTOMER_TOKEN', token: '<token>' | ''}
 * ('' for a guest).
 *
 * The endpoint URL comes from this script tag's data-emporiqa-config, so
 * the page needs no inline script and says nothing about the shopper. The
 * chat opening costs one uncached POST to the token endpoint (a guest gets
 * ''); the answer is reused for at most five minutes, so a tab left open
 * never hands the widget an expired token or one from a session that has
 * since signed out. A failed answer is never reused.
 */
(function () {
  'use strict';

  // Read at the top level: document.currentScript is null in callbacks.
  var script = document.currentScript;
  var attr = script && script.getAttribute('data-emporiqa-config');
  var config = {};
  try {
    // A page cached from before 1.12.0 still has the inline config.
    config = (attr ? JSON.parse(attr) : window.emporiqaTokenConfig) || {};
  } catch (e) {
    // Unreadable config: stay off, as without one.
  }
  if (!config.url || !window.fetch) {
    return;
  }

  var origin = window.location.origin;
  var MAX_AGE_MS = 5 * 60 * 1000;
  var answer = null;
  var answeredAt = 0;

  function fetchToken() {
    if (answer && Date.now() - answeredAt < MAX_AGE_MS) {
      return answer;
    }
    answeredAt = Date.now();
    answer = fetch(config.url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('token request failed: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        return data && typeof data.token === 'string' ? data.token : '';
      })
      .catch(function () {
        // Not reused, so the next request tries again.
        answer = null;
        return '';
      });
    return answer;
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window || event.origin !== origin) {
      return;
    }
    var data = event.data;
    if (!data || data.type !== 'EMPORIQA_TOKEN_REQUEST') {
      return;
    }
    var port = event.ports && event.ports[0];
    var reply = port
      ? function (message) {
          port.postMessage(message);
        }
      // Legacy: an embed.js from before the MessageChannel handshake sends
      // no port and listens on the window. Remove once cached copies of
      // that embed.js have expired.
      : function (message) {
          window.postMessage(message, origin);
        };
    reply({ type: 'EMPORIQA_TOKEN_PENDING' });
    fetchToken().then(function (token) {
      reply({ type: 'EMPORIQA_CUSTOMER_TOKEN', token: token });
    });
  });
})();
