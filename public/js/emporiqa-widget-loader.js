/**
 * Emporiqa widget loader for Sylius.
 *
 * Reads configuration from window.emporiqaConfig (set by Twig),
 * fetches a signed user token for authenticated users via AJAX,
 * caches it in sessionStorage, and injects the widget script tag.
 */
(function () {
  'use strict';

  var config = window.emporiqaConfig || {};
  if (!config.widgetBaseUrl || !config.storeId) {
    return;
  }

  var STORAGE_KEY = 'emporiqa_user_token';

  function loadWidget(userToken) {
    var params = {
      store_id: config.storeId,
      language: config.language || 'en'
    };
    if (userToken) {
      params.user_id = userToken;
    }

    var query = Object.keys(params)
      .map(function (key) {
        return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
      })
      .join('&');

    var script = document.createElement('script');
    script.src = config.widgetBaseUrl + '?' + query;
    script.async = true;
    document.head.appendChild(script);
  }

  function getCachedToken(userId) {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      var cached = JSON.parse(raw);
      if (cached && cached.uid === userId) {
        return cached.token;
      }
    } catch (e) {
      // sessionStorage unavailable (private browsing, quota, etc.)
    }
    return null;
  }

  function setCachedToken(userId, token) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ uid: userId, token: token }));
    } catch (e) {
      // Silently ignore storage errors
    }
  }

  function clearCachedToken() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) {
      // Silently ignore storage errors
    }
  }

  var userId = config.userId || 0;

  if (userId > 0) {
    var cachedToken = getCachedToken(userId);
    if (cachedToken) {
      loadWidget(cachedToken);
    } else {
      fetch('/emporiqa/api/user-token', { credentials: 'same-origin' })
        .then(function (response) {
          return response.ok ? response.json() : null;
        })
        .then(function (data) {
          var token = data && data.token ? data.token : null;
          if (token) {
            setCachedToken(userId, token);
          }
          loadWidget(token);
        })
        .catch(function () {
          loadWidget(null);
        });
    }
  } else {
    clearCachedToken();
    loadWidget(null);
  }
})();
