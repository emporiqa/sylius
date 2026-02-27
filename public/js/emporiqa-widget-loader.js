/**
 * Emporiqa widget loader for Sylius.
 *
 * Reads window.emporiqaConfig (set by Twig) and loads the widget script.
 * The signed user token (userId) is embedded server-side for authenticated
 * users — no AJAX calls needed. Anonymous pages have no token.
 */
(function () {
  'use strict';

  var config = window.emporiqaConfig || {};
  if (!config.widgetBaseUrl || !config.storeId) {
    return;
  }

  var params = {
    store_id: config.storeId,
    language: config.language || 'en'
  };
  if (config.currency) {
    params.currency = config.currency;
  }
  if (config.channel) {
    params.channel = config.channel;
  }
  if (config.userId) {
    params.user_id = config.userId;
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
})();
