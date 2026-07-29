/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/public-portal/api.js"
/*!**********************************!*\
  !*** ./src/public-portal/api.js ***!
  \**********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   api: () => (/* binding */ api),
/* harmony export */   unwrapCollection: () => (/* binding */ unwrapCollection),
/* harmony export */   unwrapEntity: () => (/* binding */ unwrapEntity)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);


const portalConfig = () => window.afPublicPortal || {};
const ep = () => portalConfig().endpoints || {};
function buildHeaders(extraHeaders = {}) {
  const config = portalConfig();
  const headers = {
    ...extraHeaders
  };

  // Signed-in users authorize every request with the REST nonce (the server
  // requires it on writes). The portal is an authenticated console, so there
  // is no anonymous data path — anonymous visitors see the sign-in gate.
  if (config.nonce) {
    headers['X-WP-Nonce'] = config.nonce;
  }
  if (config.demoMode) {
    headers['X-AF-Demo-Mode'] = '1';
  }
  return headers;
}
function normalizeError(error) {
  const message = error?.data?.error?.message || error?.data?.message || error?.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Request failed', 'bushido-almost-famous');
  const normalized = new Error(message);
  normalized.code = error?.data?.error?.code || error?.code || 'request_failed';
  normalized.detail = error?.data?.error?.detail || '';
  normalized.status = error?.statusCode || error?.data?.status || 500;
  return normalized;
}
async function request({
  path,
  method = 'GET',
  data
}) {
  try {
    return await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path,
      method,
      data,
      headers: buildHeaders()
    });
  } catch (error) {
    throw normalizeError(error);
  }
}
function unwrapEntity(response) {
  return response?.data ?? response ?? null;
}
function unwrapCollection(response) {
  const payload = unwrapEntity(response);
  if (Array.isArray(payload)) {
    return payload;
  }
  if (Array.isArray(payload?.data)) {
    return payload.data;
  }
  return [];
}
const api = {
  listCampaigns: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    const base = ep().campaigns || '/almost-famous/v1/campaigns';
    return request({
      path: qs ? `${base}?${qs}` : base
    });
  },
  getCampaign: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}`
  }),
  createCampaign: data => request({
    path: ep().campaigns || '/almost-famous/v1/campaigns',
    method: 'POST',
    data
  }),
  pauseCampaign: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/pause`,
    method: 'POST'
  }),
  resumeCampaign: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/resume`,
    method: 'POST'
  }),
  archiveCampaign: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/archive`,
    method: 'POST'
  }),
  duplicateCampaign: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/duplicate`,
    method: 'POST'
  }),
  getCampaignAnalytics: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/analytics`
  }),
  refreshCampaignMetrics: id => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${id}/metrics/refresh`,
    method: 'POST'
  }),
  createCheckout: (campaignId, data) => request({
    path: `${ep().campaigns || '/almost-famous/v1/campaigns'}/${campaignId}/checkout`,
    method: 'POST',
    data
  }),
  listAudiences: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    const base = ep().audiences || '/almost-famous/v1/audiences';
    return request({
      path: qs ? `${base}?${qs}` : base
    });
  },
  listCreatives: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    const base = ep().creatives || '/almost-famous/v1/creatives';
    return request({
      path: qs ? `${base}?${qs}` : base
    });
  },
  listPayments: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    const base = ep().payments || '/almost-famous/v1/payments';
    return request({
      path: qs ? `${base}?${qs}` : base
    });
  },
  getPayment: id => request({
    path: `${ep().payments || '/almost-famous/v1/payments'}/${id}`
  }),
  refundPayment: (id, data) => request({
    path: `${ep().payments || '/almost-famous/v1/payments'}/${id}/refund`,
    method: 'POST',
    data
  })
};

/***/ },

/***/ "./src/public-portal/utils.js"
/*!************************************!*\
  !*** ./src/public-portal/utils.js ***!
  \************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   StatusBadge: () => (/* binding */ StatusBadge),
/* harmony export */   canManage: () => (/* binding */ canManage),
/* harmony export */   canView: () => (/* binding */ canView),
/* harmony export */   formatCurrency: () => (/* binding */ formatCurrency),
/* harmony export */   formatDate: () => (/* binding */ formatDate),
/* harmony export */   formatPlatform: () => (/* binding */ formatPlatform),
/* harmony export */   isDemo: () => (/* binding */ isDemo),
/* harmony export */   portalConfig: () => (/* binding */ portalConfig),
/* harmony export */   t: () => (/* binding */ t),
/* harmony export */   useRouter: () => (/* binding */ useRouter)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);


/**
 * Localized portal configuration (capabilities, i18n, endpoints).
 */

function portalConfig() {
  return window.afPublicPortal || {};
}

/**
 * Demo mode serves in-process fixtures with no real data or writes, so it
 * grants the full UI to anyone for local testing.
 */
function isDemo() {
  return !!portalConfig().demoMode;
}

/**
 * Whether the current visitor may view campaigns (signed-in with the AF
 * view capability, or demo mode).
 */
function canView() {
  return isDemo() || !!portalConfig().canView;
}

/**
 * Whether the current visitor may create/modify campaigns and payments.
 */
function canManage() {
  return isDemo() || !!portalConfig().canManage;
}

/**
 * Look up a localized string with a fallback.
 * @param {string} key      Localization key.
 * @param {string} fallback Default English value.
 * @return {string} Localized value.
 */
function t(key, fallback) {
  return portalConfig().i18n?.[key] || fallback;
}

/**
 * Minimal hash-based router for the portal SPA.
 * Routes: #/ #/campaigns/new #/campaigns/:id #/campaigns/:id/analytics #/payments
 */
function useRouter() {
  const [route, setRoute] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(() => parseHash(window.location.hash));
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const onChange = () => setRoute(parseHash(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  const navigate = hash => {
    window.location.hash = hash;
  };
  return {
    ...route,
    navigate
  };
}
function parseHash(hash) {
  const raw = (hash || '#/').replace(/^#\/?/, '');
  const parts = raw.split('/').filter(Boolean);

  // #/ → dashboard
  if (parts.length === 0) {
    return {
      view: 'dashboard',
      id: null
    };
  }
  // #/payments
  if (parts[0] === 'payments') {
    return {
      view: 'payments',
      id: null
    };
  }
  // #/campaigns/new
  if (parts[0] === 'campaigns' && parts[1] === 'new') {
    return {
      view: 'create',
      id: null
    };
  }
  // #/campaigns/:id/analytics
  if (parts[0] === 'campaigns' && parts[2] === 'analytics') {
    return {
      view: 'analytics',
      id: parts[1]
    };
  }
  // #/campaigns/:id/checkout
  if (parts[0] === 'campaigns' && parts[2] === 'checkout') {
    return {
      view: 'checkout',
      id: parts[1]
    };
  }
  // #/campaigns/:id
  if (parts[0] === 'campaigns' && parts[1]) {
    return {
      view: 'detail',
      id: parts[1]
    };
  }
  return {
    view: 'dashboard',
    id: null
  };
}

/**
 * Badge component for campaign/payment status.
 * @param {Object} root0        Component properties.
 * @param {string} root0.status Status value.
 * @return {JSX.Element} Status badge.
 */
function StatusBadge({
  status
}) {
  const normalized = (status || 'UNKNOWN').toString();
  const cssStatus = normalized.toLowerCase();
  const label = normalized.replace(/_/g, ' ').toLowerCase();
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("span", {
    className: `af-badge af-badge-${cssStatus}`,
    children: label
  });
}

/**
 * Formats a number as currency (USD).
 * @param {number|string|null} amount   Amount to format.
 * @param {string}             currency ISO currency code.
 * @return {string} Formatted currency.
 */
function formatCurrency(amount, currency = 'USD') {
  if (amount === null || amount === undefined) {
    return '—';
  }
  const locale = window.afPublicPortal && window.afPublicPortal.locale || undefined;
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency
  }).format(Number(amount));
}

/**
 * Formats ISO date string to human-readable.
 * @param {string} dateStr ISO date.
 * @return {string} Localized date.
 */
function formatDate(dateStr) {
  if (!dateStr) {
    return '—';
  }
  const locale = window.afPublicPortal && window.afPublicPortal.locale || undefined;
  return new Date(dateStr).toLocaleDateString(locale, {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  });
}
function formatPlatform(platform) {
  if (!platform) {
    return '—';
  }
  const normalized = platform.toString().toUpperCase();
  const labels = {
    META: 'Meta',
    GOOGLE: 'Google',
    SPOTIFY: 'Spotify',
    TIKTOK: 'TikTok'
  };
  return labels[normalized] || normalized;
}

/***/ },

/***/ "./src/public-portal/views/CampaignAnalytics.js"
/*!******************************************************!*\
  !*** ./src/public-portal/views/CampaignAnalytics.js ***!
  \******************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CampaignAnalytics: () => (/* binding */ CampaignAnalytics)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Campaign analytics view — key metrics in a stat row + table.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Analytics view.
 */

function CampaignAnalytics({
  id,
  navigate
}) {
  const [metrics, setMetrics] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [refreshing, setRefreshing] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const load = () => _api__WEBPACK_IMPORTED_MODULE_2__.api.getCampaignAnalytics(id).then(res => {
    setMetrics((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res));
    setLoading(false);
  }).catch(err => {
    setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load analytics', 'bushido-almost-famous'));
    setLoading(false);
  });
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);
  const handleRefresh = () => {
    setRefreshing(true);
    setError(null);
    // Ask the backend to re-pull fresh numbers from the ad platforms,
    // then refetch the aggregate.
    _api__WEBPACK_IMPORTED_MODULE_2__.api.refreshCampaignMetrics(id).catch(() => null) // A failed refresh still falls through to a refetch.
    .then(() => load()).then(() => setRefreshing(false));
  };
  if (loading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-loading",
      role: "status",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading analytics…', 'bushido-almost-famous')
    });
  }
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-page-header",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign Analytics', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary",
          onClick: () => navigate(`#/campaigns/${id}`),
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-alert af-alert-error",
        role: "alert",
        children: error
      })]
    });
  }

  // Normalize — af-server may return different shapes
  const data = metrics || {};
  const summary = data.summary || data;
  const stats = [{
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Impressions', 'bushido-almost-famous'),
    value: summary.impressions ?? summary.totalImpressions ?? 0
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Clicks', 'bushido-almost-famous'),
    value: summary.clicks ?? summary.totalClicks ?? 0
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spend', 'bushido-almost-famous'),
    value: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(summary.spend ?? summary.totalSpend ?? 0)
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('CTR', 'bushido-almost-famous'),
    value: `${((summary.ctr ?? (summary.clicks && summary.impressions ? summary.clicks / summary.impressions * 100 : 0)) || 0).toFixed(2)}%`
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('CPC', 'bushido-almost-famous'),
    value: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(summary.cpc ?? (summary.clicks > 0 ? (summary.spend || 0) / summary.clicks : 0))
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('CPM', 'bushido-almost-famous'),
    value: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(summary.cpm ?? (summary.impressions > 0 ? (summary.spend || 0) / summary.impressions * 1000 : 0))
  }];

  // Platform breakdown if available
  const platformBreakdown = data.platforms || data.byPlatform || [];
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-page-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign Analytics', 'bushido-almost-famous')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        style: {
          display: 'flex',
          gap: '8px'
        },
        children: [(0,_utils__WEBPACK_IMPORTED_MODULE_3__.canManage)() && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary",
          onClick: handleRefresh,
          disabled: refreshing,
          children: refreshing ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Refreshing…', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Refresh metrics', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary",
          onClick: () => navigate(`#/campaigns/${id}`),
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-stats-row",
      children: stats.map(s => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-stat-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-value",
          children: typeof s.value === 'number' ? s.value.toLocaleString() : s.value
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-label",
          children: s.label
        })]
      }, s.label))
    }), Array.isArray(platformBreakdown) && platformBreakdown.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-card",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h3", {
        style: {
          margin: '0 0 12px',
          fontSize: '16px',
          fontWeight: 600
        },
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('By Platform', 'bushido-almost-famous')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-table-wrap",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("table", {
          className: "af-table",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("thead", {
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Platform', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Impressions', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Clicks', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spend', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('CTR', 'bushido-almost-famous')
              })]
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("tbody", {
            children: platformBreakdown.map((p, i) => {
              let ctr = '0.00';
              if (p.ctr !== null && p.ctr !== undefined) {
                ctr = Number(p.ctr).toFixed(2);
              } else if (p.impressions > 0) {
                ctr = (p.clicks / p.impressions * 100).toFixed(2);
              }
              return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                  children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatPlatform)(p.platform)
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                  children: (p.impressions ?? 0).toLocaleString()
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                  children: (p.clicks ?? 0).toLocaleString()
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                  children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(p.spend ?? 0)
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("td", {
                  children: [ctr, "%"]
                })]
              }, p.platform || i);
            })
          })]
        })
      })]
    })]
  });
}

/***/ },

/***/ "./src/public-portal/views/CampaignDetail.js"
/*!***************************************************!*\
  !*** ./src/public-portal/views/CampaignDetail.js ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CampaignDetail: () => (/* binding */ CampaignDetail)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Campaign detail view — shows full info with lifecycle action buttons.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign detail view.
 */

function CampaignDetail({
  id,
  navigate
}) {
  const [campaign, setCampaign] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [actionLoading, setActionLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [actionError, setActionError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const load = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setLoading(true);
    _api__WEBPACK_IMPORTED_MODULE_2__.api.getCampaign(id).then(res => {
      setCampaign((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res));
      setLoading(false);
    }).catch(err => {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load campaign', 'bushido-almost-famous'));
      setLoading(false);
    });
  }, [id]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    load();
  }, [load]);
  const doAction = async (action, label) => {
    // Native confirmation prevents accidental campaign lifecycle changes.
    // eslint-disable-next-line no-alert
    const confirmed = window.confirm((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: campaign action such as pause or archive. */
    (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Are you sure you want to %s this campaign?', 'bushido-almost-famous'), label));
    if (!confirmed) {
      return;
    }
    setActionError(null);
    setActionLoading(action);
    try {
      if (action === 'pause') {
        await _api__WEBPACK_IMPORTED_MODULE_2__.api.pauseCampaign(id);
      } else if (action === 'resume') {
        await _api__WEBPACK_IMPORTED_MODULE_2__.api.resumeCampaign(id);
      } else if (action === 'archive') {
        await _api__WEBPACK_IMPORTED_MODULE_2__.api.archiveCampaign(id);
      } else if (action === 'duplicate') {
        const res = await _api__WEBPACK_IMPORTED_MODULE_2__.api.duplicateCampaign(id);
        const newId = (0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res)?.id;
        if (newId) {
          navigate(`#/campaigns/${newId}`);
          return;
        }
      }
      load(); // Refresh
    } catch (err) {
      setActionError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: 1: campaign action, 2: API error message. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to %1$s: %2$s', 'bushido-almost-famous'), label, err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Unknown error', 'bushido-almost-famous')));
    } finally {
      setActionLoading(null);
    }
  };
  if (loading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-loading",
      role: "status",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading campaign…', 'bushido-almost-famous')
    });
  }
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      role: "alert",
      children: error
    });
  }
  if (!campaign) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign not found', 'bushido-almost-famous')
    });
  }
  const s = (campaign.status || '').toUpperCase();
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-page-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: campaign.name || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Untitled Campaign', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("p", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_utils__WEBPACK_IMPORTED_MODULE_3__.StatusBadge, {
            status: campaign.status
          }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Created', 'bushido-almost-famous'), ' ', (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatDate)(campaign.createdAt)]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        style: {
          display: 'flex',
          gap: '8px'
        },
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("button", {
          className: "af-btn af-btn-secondary",
          onClick: () => navigate(`#/campaigns/${id}/analytics`),
          children: ["\uD83D\uDCCA ", (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Analytics', 'bushido-almost-famous')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary",
          onClick: () => navigate('#/'),
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-stats-row",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-stat-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-value",
          children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(campaign.budgetTotal || campaign.budgetDaily, campaign.currency || 'USD')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Budget', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-stat-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-value",
          children: campaign.objective ? campaign.objective.replace(/_/g, ' ').toLowerCase() : '—'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Objective', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-stat-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-value",
          children: campaign.creationMode || '—'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Mode', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-stat-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-value",
          children: campaign.currency || 'USD'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-stat-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Currency', 'bushido-almost-famous')
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-card",
      style: {
        marginBottom: '16px'
      },
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h3", {
        style: {
          margin: '0 0 12px',
          fontSize: '16px',
          fontWeight: 600
        },
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Details', 'bushido-almost-famous')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-detail-grid",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Status', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_utils__WEBPACK_IMPORTED_MODULE_3__.StatusBadge, {
            status: campaign.status
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Daily Budget', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(campaign.budgetDaily, campaign.currency || 'USD')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Total Budget', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(campaign.budgetTotal, campaign.currency || 'USD')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Start Date', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatDate)(campaign.startDate)
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('End Date', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatDate)(campaign.endDate)
        }), campaign.targeting && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Targeting', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-value",
            children: typeof campaign.targeting === 'object' ? JSON.stringify(campaign.targeting, null, 2).substring(0, 200) : String(campaign.targeting)
          })]
        })]
      })]
    }), campaign.campaignPlatforms && campaign.campaignPlatforms.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-card",
      style: {
        marginBottom: '16px'
      },
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h3", {
        style: {
          margin: '0 0 12px',
          fontSize: '16px',
          fontWeight: 600
        },
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Platforms', 'bushido-almost-famous')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-table-wrap",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("table", {
          className: "af-table",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("thead", {
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Platform', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Allocation', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Status', 'bushido-almost-famous')
              })]
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("tbody", {
            children: campaign.campaignPlatforms.map(p => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatPlatform)(p.platform)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: p.budgetAllocation !== null && p.budgetAllocation !== undefined ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: platform budget allocation percentage. */
                (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('%1$s%%', 'bushido-almost-famous'), p.budgetAllocation) : '—'
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_utils__WEBPACK_IMPORTED_MODULE_3__.StatusBadge, {
                  status: p.platformStatus || p.status || 'pending'
                })
              })]
            }, p.id || p.platform))
          })]
        })
      })]
    }), (0,_utils__WEBPACK_IMPORTED_MODULE_3__.canManage)() && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-card",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h3", {
        style: {
          margin: '0 0 12px',
          fontSize: '16px',
          fontWeight: 600
        },
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Actions', 'bushido-almost-famous')
      }), actionError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-alert af-alert-error",
        role: "alert",
        children: actionError
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        style: {
          display: 'flex',
          gap: '8px',
          flexWrap: 'wrap'
        },
        children: [s === 'DRAFT' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("button", {
          className: "af-btn af-btn-success",
          onClick: () => navigate(`#/campaigns/${id}/checkout`),
          disabled: !!actionLoading,
          children: ["\uD83D\uDCB3", ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Checkout & Pay', 'bushido-almost-famous')]
        }), s === 'ACTIVE' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-warning af-btn-sm",
          onClick: () => doAction('pause', 'pause'),
          disabled: !!actionLoading,
          children: actionLoading === 'pause' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Pausing…', 'bushido-almost-famous') : '⏸ ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Pause', 'bushido-almost-famous')
        }), s === 'PAUSED' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-success af-btn-sm",
          onClick: () => doAction('resume', 'resume'),
          disabled: !!actionLoading,
          children: actionLoading === 'resume' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Resuming…', 'bushido-almost-famous') : '▶ ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Resume', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary af-btn-sm",
          onClick: () => doAction('duplicate', 'duplicate'),
          disabled: !!actionLoading,
          children: actionLoading === 'duplicate' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Duplicating…', 'bushido-almost-famous') : '📋 ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Duplicate', 'bushido-almost-famous')
        }), s !== 'ARCHIVED' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-danger af-btn-sm",
          onClick: () => doAction('archive', 'archive'),
          disabled: !!actionLoading,
          children: actionLoading === 'archive' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Archiving…', 'bushido-almost-famous') : '🗃 ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Archive', 'bushido-almost-famous')
        })]
      })]
    })]
  });
}

/***/ },

/***/ "./src/public-portal/views/Checkout.js"
/*!*********************************************!*\
  !*** ./src/public-portal/views/Checkout.js ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   Checkout: () => (/* binding */ Checkout)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Days between the campaign's start and end dates — the duration the checkout
 * charges for (the backend bills `budgetDaily × durationDays` for a FIXED
 * duration). Falls back to 1 day when the dates are missing or invalid.
 * @param {Object|null} campaign Campaign data.
 * @return {number} Billable duration in days.
 */

function campaignDurationDays(campaign) {
  const start = campaign?.startDate ? new Date(campaign.startDate) : null;
  const end = campaign?.endDate ? new Date(campaign.endDate) : null;
  if (!start || !end || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
    return 1;
  }
  return Math.max(1, Math.ceil((end.getTime() - start.getTime()) / 86400000));
}

/**
 * Checkout flow — prepares the real server-side order (which computes the
 * charge from the stored budget plus the organization's service fee), shows
 * the exact amounts from the created payment record, then hands off to Stripe.
 * @param {Object}   root0          Component properties.
 * @param {string}   root0.id       Campaign ID.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Checkout view.
 */
function Checkout({
  id,
  navigate
}) {
  const [campaign, setCampaign] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  // { checkoutUrl, payment } once the server has created the order. The
  // payment record carries the authoritative amount/serviceFee/totalCharged.
  const [order, setOrder] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [submitting, setSubmitting] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    _api__WEBPACK_IMPORTED_MODULE_2__.api.getCampaign(id).then(res => {
      setCampaign((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res));
      setLoading(false);
    }).catch(err => {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load campaign', 'bushido-almost-famous'));
      setLoading(false);
    });
  }, [id]);
  const durationDays = campaignDurationDays(campaign);
  const handlePrepare = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const res = await _api__WEBPACK_IMPORTED_MODULE_2__.api.createCheckout(id, {
        successUrl: window.location.href.split('#')[0] + `#/campaigns/${id}`,
        cancelUrl: window.location.href.split('#')[0] + `#/campaigns/${id}`,
        durationType: 'FIXED',
        durationDays
      });
      const checkout = (0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res);
      if (!checkout?.checkoutUrl) {
        setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No checkout URL received. The campaign may not be ready for checkout.', 'bushido-almost-famous'));
        return;
      }
      // The checkout response carries no amounts; the payment record it
      // created does. Fetch it so the summary shows the exact figures
      // Stripe will charge. A failure here is non-fatal — Stripe shows
      // the same total on its payment page.
      let payment = null;
      if (checkout.paymentId) {
        try {
          payment = (0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(await _api__WEBPACK_IMPORTED_MODULE_2__.api.getPayment(checkout.paymentId));
        } catch {
          payment = null;
        }
      }
      setOrder({
        checkoutUrl: checkout.checkoutUrl,
        payment
      });
    } catch (err) {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to create checkout session', 'bushido-almost-famous'));
    } finally {
      setSubmitting(false);
    }
  };
  if (loading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-loading",
      role: "status",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading checkout…', 'bushido-almost-famous')
    });
  }
  if (error && !campaign) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-page-header",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Checkout', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          className: "af-btn af-btn-secondary",
          onClick: () => navigate(`#/campaigns/${id}`),
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-alert af-alert-error",
        role: "alert",
        children: error
      })]
    });
  }

  // Pre-order estimate mirroring the backend's charge basis for a FIXED
  // duration: daily budget × days when a daily budget is set, else the
  // stored total. Fees are intentionally NOT estimated here — the service
  // fee rate is org-specific and only the server knows it, so the fee and
  // total render from the real payment record after the order is prepared.
  const budgetDaily = Number(campaign?.budgetDaily) || 0;
  const budgetTotal = Number(campaign?.budgetTotal) || 0;
  const estimatedBudget = budgetDaily > 0 ? budgetDaily * durationDays : budgetTotal;
  const currency = campaign?.currency || 'USD';
  const payment = order?.payment;
  const totalCharged = Number(payment?.totalCharged);
  const hasAmounts = Number.isFinite(totalCharged);
  const licensingFee = Number(payment?.licensingFee) || 0;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-page-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Checkout', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
          children: campaign?.name || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
        className: "af-btn af-btn-secondary",
        onClick: () => navigate(`#/campaigns/${id}`),
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
      })]
    }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      role: "alert",
      children: error
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-card",
      style: {
        maxWidth: '500px'
      },
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h3", {
        style: {
          margin: '0 0 16px',
          fontSize: '16px',
          fontWeight: 600
        },
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Order Summary', 'bushido-almost-famous')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-detail-grid",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: campaign?.name || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Untitled', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Objective', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          style: {
            textTransform: 'capitalize'
          },
          children: campaign?.objective ? campaign.objective.replace(/_/g, ' ').toLowerCase() : '—'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-label",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Duration', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
          className: "af-detail-value",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: number of days the campaign runs. */
          (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__._n)('%d day', '%d days', durationDays, 'bushido-almost-famous'), durationDays)
        }), !order && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Ad Budget', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-value",
            children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(estimatedBudget, currency)
          })]
        }), order && hasAmounts && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Ad Budget', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-value",
            children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(payment.amount, payment.currency || currency)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Service Fee', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-value",
            children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(payment.serviceFee, payment.currency || currency)
          }), licensingFee > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
              className: "af-detail-label",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Licensing Fee', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
              className: "af-detail-value",
              children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(licensingFee, payment.currency || currency)
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-label",
            style: {
              fontWeight: 700,
              fontSize: '14px'
            },
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Total', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-detail-value",
            style: {
              fontWeight: 700,
              fontSize: '18px',
              color: '#6c5ce7'
            },
            children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(totalCharged, payment.currency || currency)
          })]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        style: {
          marginTop: '24px'
        },
        children: !order ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            className: "af-btn af-btn-primary",
            onClick: handlePrepare,
            disabled: submitting,
            style: {
              width: '100%',
              justifyContent: 'center',
              padding: '14px'
            },
            children: submitting ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Preparing order…', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Review Order →', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
            style: {
              fontSize: '12px',
              color: '#636e72',
              textAlign: 'center',
              marginTop: '8px'
            },
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The exact total, including your organization’s service fee, is calculated by the server and shown before you pay.', 'bushido-almost-famous')
          })]
        }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            className: "af-btn af-btn-primary",
            onClick: () => {
              window.location.href = order.checkoutUrl;
            },
            style: {
              width: '100%',
              justifyContent: 'center',
              padding: '14px'
            },
            children: hasAmounts ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: formatted checkout total. */
            (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Pay %s →', 'bushido-almost-famous'), (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(totalCharged, payment.currency || currency)) : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Continue to Stripe →', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
            style: {
              fontSize: '12px',
              color: '#636e72',
              textAlign: 'center',
              marginTop: '8px'
            },
            children: hasAmounts ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('You will be redirected to Stripe for secure payment.', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The final total, including your organization’s service fee, is shown on the secure Stripe payment page.', 'bushido-almost-famous')
          })]
        })
      })]
    })]
  });
}

/***/ },

/***/ "./src/public-portal/views/CreateCampaign.js"
/*!***************************************************!*\
  !*** ./src/public-portal/views/CreateCampaign.js ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CreateCampaign: () => (/* binding */ CreateCampaign)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





const OBJECTIVES = [{
  value: 'AWARENESS',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Brand Awareness', 'bushido-almost-famous')
}, {
  value: 'TRAFFIC',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Traffic', 'bushido-almost-famous')
}, {
  value: 'ENGAGEMENT',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Engagement', 'bushido-almost-famous')
}, {
  value: 'CONVERSIONS',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Conversions', 'bushido-almost-famous')
}, {
  value: 'VIDEO_VIEWS',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video Views', 'bushido-almost-famous')
}, {
  value: 'STREAMING',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Streaming', 'bushido-almost-famous')
}];
const PLATFORMS = ['META', 'GOOGLE', 'SPOTIFY', 'TIKTOK'];

// Ad-copy length limits mirror the service's pre-checkout creative gate, which
// refuses POST /payments/checkout when the copy would fail a platform's
// validator at launch. Validating the same bounds here turns the server's 422
// into an inline, per-field message.
const META_TITLE_MAX = 40;
const META_DESCRIPTION_MAX = 30;
const GOOGLE_TITLE_MAX = 30;
const GOOGLE_VIDEO_TITLE_MAX = 40;
const GOOGLE_DESCRIPTION_MAX = 90;
const SPOTIFY_TITLE_MAX = 40;
const TIKTOK_AD_TEXT_MAX = 100;
const CREATIVE_TYPE_LABELS = {
  image: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Image', 'bushido-almost-famous'),
  video: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video', 'bushido-almost-famous'),
  audio: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Audio', 'bushido-almost-famous')
};

/**
 * Mirrors `isValidHttpUrl` in the backend gate: parseable AND http(s).
 * @param {string} value Candidate URL.
 * @return {boolean} Whether the value is an HTTP(S) URL.
 */
function isValidHttpUrl(value) {
  let parsed;
  try {
    parsed = new URL(value);
  } catch {
    return false;
  }
  return parsed.protocol === 'http:' || parsed.protocol === 'https:';
}

/**
 * Mirrors the gate's `isSpotifyUri`: a `spotify:<type>:<id>` URI or an
 * open.spotify.com / spotify.link URL. Engagement/streaming campaigns on
 * Spotify must click through to a Spotify destination.
 * @param {string} value Candidate Spotify destination.
 * @return {boolean} Whether the value is a Spotify destination.
 */
function isSpotifyLink(value) {
  return /^spotify:[a-z]+:[a-zA-Z0-9]+/.test(value) || /^https?:\/\/(open\.spotify\.com|spotify\.link)\//i.test(value);
}

/**
 * Accepts a raw YouTube video id or a youtube.com / youtu.be URL and returns
 * the video id, or '' when it can't be parsed.
 * @param {string} value YouTube URL or video ID.
 * @return {string} Parsed video ID, or an empty string.
 */
function parseYouTubeId(value) {
  const trimmed = (value || '').trim();
  if (!trimmed) {
    return '';
  }
  const match = trimmed.match(/(?:youtube\.com\/(?:watch\?.*v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{6,})/);
  if (match) {
    return match[1];
  }
  return /^[\w-]{6,}$/.test(trimmed) ? trimmed : '';
}

/**
 * Client-side mirror of the backend pre-checkout creative gate
 * (validateCampaignCreative + validateConversionTracking). Returns an array of
 * friendly error strings; empty means the campaign should pass the server
 * gate's presence/length rules for the selected platforms.
 * @param {Object}   root0                Validation input.
 * @param {string[]} root0.platforms      Selected platform keys.
 * @param {string}   root0.objective      Campaign objective.
 * @param {string}   root0.title          Ad title.
 * @param {string}   root0.description    Ad description.
 * @param {string}   root0.externalLink   Destination URL.
 * @param {string}   root0.youtubeVideoId YouTube video ID.
 * @param {string}   root0.pixelId        Tracking pixel ID.
 * @param {boolean}  root0.hasVideo       Whether video is selected.
 * @param {boolean}  root0.hasImage       Whether image is selected.
 * @param {boolean}  root0.hasAudio       Whether audio is selected.
 * @return {string[]} Validation errors.
 */
function validateAdContent({
  platforms,
  objective,
  title,
  description,
  externalLink,
  youtubeVideoId,
  pixelId,
  hasVideo,
  hasImage,
  hasAudio
}) {
  const errors = [];
  const has = p => platforms.includes(p);
  if (!externalLink) {
    errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Add a destination link — every platform needs a landing page URL for the ad.', 'bushido-almost-famous'));
  } else if (!isValidHttpUrl(externalLink)) {
    errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The destination link must be a full http(s) URL, e.g. https://example.com.', 'bushido-almost-famous'));
  }
  if (has('META')) {
    if (!hasVideo && !hasImage) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Meta: select an image or video creative for the ad.', 'bushido-almost-famous'));
    }
    if (title.length > META_TITLE_MAX) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum Meta title length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Meta: the ad title must be %d characters or fewer.', 'bushido-almost-famous'), META_TITLE_MAX));
    }
    if (description.length > META_DESCRIPTION_MAX) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum Meta description length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Meta: the ad description must be %d characters or fewer.', 'bushido-almost-famous'), META_DESCRIPTION_MAX));
    }
  }
  if (has('GOOGLE')) {
    // A VIDEO_VIEWS objective routes Google through the YouTube (Demand
    // Gen) path, which needs a YouTube-hosted video plus a logo/cover
    // image; other objectives synthesize a responsive display ad from a
    // landscape marketing image.
    const isVideoBranch = objective === 'VIDEO_VIEWS';
    if (isVideoBranch && !youtubeVideoId) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google/YouTube: Video Views campaigns need a YouTube video — paste a YouTube link or video ID (an uploaded file can’t run as a Google video ad).', 'bushido-almost-famous'));
    }
    if (!hasImage) {
      errors.push(isVideoBranch ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google/YouTube: select a square image creative to use as the logo/cover image.', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google Ads: select an image creative to use as the marketing image.', 'bushido-almost-famous'));
    }
    if (!title) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google Ads: add an ad title (used as the headline).', 'bushido-almost-famous'));
    }
    if (!description) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google Ads: add an ad description.', 'bushido-almost-famous'));
    }
    const titleMax = isVideoBranch ? GOOGLE_VIDEO_TITLE_MAX : GOOGLE_TITLE_MAX;
    if (title.length > titleMax) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum Google title length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google Ads: the ad title must be %d characters or fewer.', 'bushido-almost-famous'), titleMax));
    }
    if (description.length > GOOGLE_DESCRIPTION_MAX) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum Google description length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Google Ads: the ad description must be %d characters or fewer.', 'bushido-almost-famous'), GOOGLE_DESCRIPTION_MAX));
    }
  }
  if (has('SPOTIFY')) {
    if (!hasAudio) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spotify: select an audio creative (an MP3 of about 30 seconds).', 'bushido-almost-famous'));
    }
    if (!hasImage) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spotify: select an image creative to use as the companion image (square, at least 600×600).', 'bushido-almost-famous'));
    }
    if (title.length > SPOTIFY_TITLE_MAX) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum Spotify title length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spotify: the ad title must be %d characters or fewer.', 'bushido-almost-famous'), SPOTIFY_TITLE_MAX));
    }
    if ((objective === 'ENGAGEMENT' || objective === 'STREAMING') && externalLink && !isSpotifyLink(externalLink)) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Spotify: engagement and streaming campaigns must link to a Spotify destination, e.g. https://open.spotify.com/track/…', 'bushido-almost-famous'));
    }
  }
  if (has('TIKTOK')) {
    if (!hasVideo && !hasImage) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('TikTok: select a video or image creative for the ad.', 'bushido-almost-famous'));
    }
    if (!title && !description) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('TikTok: add ad text (a title or a description).', 'bushido-almost-famous'));
    }
    const adText = description || title;
    if (adText.length > TIKTOK_AD_TEXT_MAX) {
      errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: maximum TikTok ad-text length. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('TikTok: the ad text (description, or title when there is no description) must be %d characters or fewer.', 'bushido-almost-famous'), TIKTOK_AD_TEXT_MAX));
    }
  }
  if (objective === 'CONVERSIONS' && (has('META') || has('TIKTOK')) && !pixelId) {
    errors.push((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Conversions campaigns on Meta or TikTok need a tracking pixel ID.', 'bushido-almost-famous'));
  }
  return errors;
}

/**
 * Campaign creation form — simplified single-page approach.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign creation form.
 */
function CreateCampaign({
  navigate
}) {
  const manage = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.canManage)();
  const [form, setForm] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({
    name: '',
    objective: 'AWARENESS',
    creationMode: 'MANUAL',
    budgetTotal: 500,
    budgetDaily: 50,
    currency: 'USD',
    startDate: new Date(Date.now() + 86400000).toISOString().split('T')[0],
    endDate: new Date(Date.now() + 86400000 * 8).toISOString().split('T')[0],
    platforms: {
      META: 50,
      GOOGLE: 50,
      SPOTIFY: 0,
      TIKTOK: 0
    }
  });
  // A portal-built campaign is launch-ready: countries feed
  // `targeting.countries`, and the audience, ad content, and creative assets
  // are sent inside the pass-through `config` object using the exact keys the
  // backend launch synthesizer and pre-checkout gate read (`title`,
  // `description`, `externalLink`, `videoAssetId`, `thumbnailAssetId`,
  // `logoAssetId`, `audioAssetId`, `audienceIds`, `youtubeVideoId`,
  // `pixelId`).
  const [countries, setCountries] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('US');
  const [audienceId, setAudienceId] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [selectedCreatives, setSelectedCreatives] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [audiences, setAudiences] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [creatives, setCreatives] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [audiencesLoading, setAudiencesLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [creativesLoading, setCreativesLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  // The destination starts at this site's configured landing page (Settings →
  // Campaign Defaults, default: the home page) rather than blank: ads that
  // land back here are the only ones the plugin's attribution cookie can tie
  // to a WooCommerce sale. Still fully editable — a Spotify engagement
  // campaign, for instance, has to point at Spotify.
  const [ad, setAd] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({
    title: '',
    description: '',
    externalLink: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.portalConfig)().defaultDestination || '',
    youtubeUrl: '',
    pixelId: ''
  });
  const [submitting, setSubmitting] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);

  // Fetch saved audiences and creatives on mount. Failures are non-blocking:
  // the user can still create a campaign without an audience or creative.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    let active = true;
    _api__WEBPACK_IMPORTED_MODULE_2__.api.listAudiences().then(res => {
      if (active) {
        setAudiences((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapCollection)(res));
      }
    }).catch(() => {
      if (active) {
        setAudiences([]);
      }
    }).finally(() => {
      if (active) {
        setAudiencesLoading(false);
      }
    });
    _api__WEBPACK_IMPORTED_MODULE_2__.api.listCreatives().then(res => {
      if (active) {
        setCreatives((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapCollection)(res));
      }
    }).catch(() => {
      if (active) {
        setCreatives([]);
      }
    }).finally(() => {
      if (active) {
        setCreativesLoading(false);
      }
    });
    return () => {
      active = false;
    };
  }, []);
  const set = (key, value) => setForm(prev => ({
    ...prev,
    [key]: value
  }));
  const setAdField = (key, value) => setAd(prev => ({
    ...prev,
    [key]: value
  }));
  const setPlatform = (platform, value) => {
    setForm(prev => ({
      ...prev,
      platforms: {
        ...prev.platforms,
        [platform]: Number(value)
      }
    }));
  };
  const toggleCreative = id => {
    setSelectedCreatives(prev => prev.includes(id) ? prev.filter(c => c !== id) : [...prev, id]);
  };

  // Map the selected assets onto the config slots the backend actually
  // consumes: the first video → `videoAssetId`, the first image →
  // `thumbnailAssetId` (main ad image), a second image → `logoAssetId`
  // (Google logo / Spotify companion), the first audio → `audioAssetId`.
  const selectedAssets = creatives.filter(c => selectedCreatives.includes(c.id));
  const ofType = type => selectedAssets.filter(c => (c.type || '').toLowerCase() === type);
  const selectedVideos = ofType('video');
  const selectedImages = ofType('image');
  const selectedAudios = ofType('audio');
  const googleSelected = form.platforms.GOOGLE > 0;
  const metaSelected = form.platforms.META > 0;
  const tiktokSelected = form.platforms.TIKTOK > 0;
  const showYouTubeField = googleSelected && form.objective === 'VIDEO_VIEWS';
  const showPixelField = form.objective === 'CONVERSIONS' && (metaSelected || tiktokSelected);
  const handleSubmit = async e => {
    e.preventDefault();
    setError(null);
    if (!form.name.trim()) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign name is required', 'bushido-almost-famous'));
      return;
    }
    const activePlatforms = Object.entries(form.platforms).filter(([, v]) => v > 0);
    if (activePlatforms.length === 0) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Select at least one platform with budget allocation', 'bushido-almost-famous'));
      return;
    }
    if (new Date(form.endDate) < new Date(form.startDate)) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('End date must be after the start date', 'bushido-almost-famous'));
      return;
    }
    const totalAlloc = activePlatforms.reduce((s, [, v]) => s + v, 0);
    if (Math.abs(totalAlloc - 100) > 1) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: current total platform allocation percentage. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Platform allocations must sum to 100%% (currently %1$s%%)', 'bushido-almost-famous'), totalAlloc));
      return;
    }
    const youtubeVideoId = showYouTubeField ? parseYouTubeId(ad.youtubeUrl) : '';
    if (showYouTubeField && ad.youtubeUrl.trim() && !youtubeVideoId) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The YouTube video must be a youtube.com / youtu.be link or a video ID.', 'bushido-almost-famous'));
      return;
    }
    const platformKeys = activePlatforms.map(([p]) => p);
    const title = ad.title.trim();
    const description = ad.description.trim();
    const externalLink = ad.externalLink.trim();
    const pixelId = ad.pixelId.trim();

    // Mirror the server's pre-checkout creative gate so the user gets an
    // inline message now instead of a 422 at checkout.
    const adErrors = validateAdContent({
      platforms: platformKeys,
      objective: form.objective,
      title,
      description,
      externalLink,
      youtubeVideoId,
      pixelId,
      hasVideo: selectedVideos.length > 0,
      hasImage: selectedImages.length > 0,
      hasAudio: selectedAudios.length > 0
    });
    if (adErrors.length > 0) {
      setError(adErrors.join(' '));
      return;
    }

    // Parse comma-separated ISO country codes; default to US if empty.
    const countryList = countries.split(',').map(c => c.trim().toUpperCase()).filter(Boolean);
    setSubmitting(true);
    try {
      const payload = {
        name: form.name,
        objective: form.objective,
        creationMode: form.creationMode,
        // Create input uses `budget` (mapped to budgetTotal server-side);
        // responses use `budgetTotal`. This is the correct request shape.
        budget: Number(form.budgetTotal),
        budgetDaily: Number(form.budgetDaily),
        currency: form.currency,
        startDate: form.startDate,
        endDate: form.endDate,
        platforms: platformKeys,
        platformAllocations: Object.fromEntries(activePlatforms),
        targeting: {
          countries: countryList.length > 0 ? countryList : ['US']
        }
      };

      // Ad content, audience, and creative selections go inside `config`,
      // not at the top level: the create schema is strict about top-level
      // keys, while `config` is a pass-through object. These are the exact
      // fields the backend launch synthesizer and the pre-checkout gate
      // read (extractSynthesisFields / extractCreativeInput).
      const config = {};
      if (title) {
        config.title = title;
      }
      if (description) {
        config.description = description;
      }
      if (externalLink) {
        config.externalLink = externalLink;
      }
      if (selectedVideos.length > 0) {
        config.videoAssetId = selectedVideos[0].id;
      }
      if (selectedImages.length > 0) {
        config.thumbnailAssetId = selectedImages[0].id;
      }
      if (selectedImages.length > 1) {
        config.logoAssetId = selectedImages[1].id;
      }
      if (selectedAudios.length > 0) {
        config.audioAssetId = selectedAudios[0].id;
      }
      if (audienceId) {
        config.audienceIds = [audienceId];
      }
      if (youtubeVideoId) {
        config.youtubeVideoId = youtubeVideoId;
      }
      if (pixelId) {
        config.pixelId = pixelId;
      }
      payload.config = config;
      const res = await _api__WEBPACK_IMPORTED_MODULE_2__.api.createCampaign(payload);
      const createdCampaign = (0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapEntity)(res);
      const campaignId = createdCampaign?.id;
      if (campaignId) {
        navigate(`#/campaigns/${campaignId}`);
      } else {
        navigate('#/');
      }
    } catch (err) {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to create campaign', 'bushido-almost-famous'));
    } finally {
      setSubmitting(false);
    }
  };

  // Management-only view (defense in depth — the router already redirects
  // view-only users away from #/create).
  if (!manage) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-info",
      role: "status",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('You have view-only access. Ask an administrator for campaign-management permissions to create campaigns.', 'bushido-almost-famous')
    });
  }
  let creativeChoices;
  if (creativesLoading) {
    creativeChoices = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-help-text",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading creatives…', 'bushido-almost-famous')
    });
  } else if (creatives.length === 0) {
    creativeChoices = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-help-text",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No creatives available.', 'bushido-almost-famous')
    });
  } else {
    creativeChoices = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      style: {
        marginTop: '8px',
        display: 'flex',
        flexDirection: 'column',
        gap: '4px'
      },
      children: creatives.map(creative => {
        const type = CREATIVE_TYPE_LABELS[(creative.type || '').toLowerCase()];
        return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("label", {
          className: "af-checkbox-label",
          htmlFor: `af-creative-${creative.id}`,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: `af-creative-${creative.id}`,
            type: "checkbox",
            checked: selectedCreatives.includes(creative.id),
            onChange: () => toggleCreative(creative.id)
          }), ' ', creative.name || creative.id, type ? ` (${type})` : '']
        }, creative.id);
      })
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-page-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Create Campaign', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Set up a new advertising campaign', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
        className: "af-btn af-btn-secondary",
        onClick: () => navigate('#/'),
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('← Back', 'bushido-almost-famous')
      })]
    }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      children: error
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-card",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("form", {
        className: "af-form",
        onSubmit: handleSubmit,
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-name",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign Name', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-name",
            type: "text",
            value: form.name,
            onChange: e => set('name', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('e.g. Summer Release Promo', 'bushido-almost-famous'),
            required: true
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-row",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-objective",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Objective', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("select", {
              id: "af-objective",
              value: form.objective,
              onChange: e => set('objective', e.target.value),
              children: OBJECTIVES.map(o => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
                value: o.value,
                children: o.label
              }, o.value))
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-mode",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Creation Mode', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("select", {
              id: "af-mode",
              value: form.creationMode,
              onChange: e => set('creationMode', e.target.value),
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
                value: "MANUAL",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Manual', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
                value: "AI",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('AI-Optimized', 'bushido-almost-famous')
              })]
            })]
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-row",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-budget-total",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Total Budget ($)', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
              id: "af-budget-total",
              type: "number",
              min: "1",
              step: "1",
              value: form.budgetTotal,
              onChange: e => set('budgetTotal', e.target.value)
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-budget-daily",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Daily Budget ($)', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
              id: "af-budget-daily",
              type: "number",
              min: "1",
              step: "1",
              value: form.budgetDaily,
              onChange: e => set('budgetDaily', e.target.value)
            })]
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-row",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-start",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Start Date', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
              id: "af-start",
              type: "date",
              value: form.startDate,
              onChange: e => set('startDate', e.target.value)
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-form-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
              htmlFor: "af-end",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('End Date', 'bushido-almost-famous')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
              id: "af-end",
              type: "date",
              value: form.endDate,
              onChange: e => set('endDate', e.target.value)
            })]
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-form-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Platform Allocation (%)', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Distribute your budget across platforms. Must sum to 100%.', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-form-row",
            style: {
              marginTop: '8px'
            },
            children: PLATFORMS.map(p => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
              className: "af-form-group",
              style: {
                gap: '4px'
              },
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
                htmlFor: `af-plat-${p}`,
                style: {
                  fontSize: '12px'
                },
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatPlatform)(p)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
                id: `af-plat-${p}`,
                type: "number",
                min: "0",
                max: "100",
                step: "1",
                value: form.platforms[p],
                onChange: e => setPlatform(p, e.target.value)
              })]
            }, p))
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-countries",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Countries', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-countries",
            type: "text",
            value: countries,
            onChange: e => setCountries(e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('US, GB, DE (comma-separated ISO codes)', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Comma-separated ISO country codes. Defaults to US if left empty.', 'bushido-almost-famous')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-audience",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Audience', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("select", {
            id: "af-audience",
            value: audienceId,
            onChange: e => setAudienceId(e.target.value),
            disabled: audiencesLoading,
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
              value: "",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No audience / Broad', 'bushido-almost-famous')
            }), audiences.map(a => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
              value: a.id,
              children: a.name || a.id
            }, a.id))]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: audiencesLoading ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading audiences…', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Choose a saved audience, or leave broad.', 'bushido-almost-famous')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-ad-title",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Ad Title', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-ad-title",
            type: "text",
            value: ad.title,
            onChange: e => setAdField('title', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('e.g. New single out now', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Used as the ad headline. Google allows up to 30 characters; Meta and Spotify up to 40.', 'bushido-almost-famous')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-ad-description",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Ad Description', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-ad-description",
            type: "text",
            value: ad.description,
            onChange: e => setAdField('description', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('One line about what you’re promoting', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Required for Google Ads (up to 90 characters). Meta caps the description at 30 characters; TikTok uses it as the ad text (up to 100).', 'bushido-almost-famous')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-ad-link",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Destination Link', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-ad-link",
            type: "url",
            value: ad.externalLink,
            onChange: e => setAdField('externalLink', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('https://example.com/landing-page', 'bushido-almost-famous'),
            required: true
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: form.objective === 'ENGAGEMENT' || form.objective === 'STREAMING' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Where the ad clicks through to. Spotify engagement/streaming campaigns must use a Spotify link (e.g. https://open.spotify.com/track/…).', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Where the ad clicks through to. Defaults to this site — clicks that land here can be matched to your WooCommerce sales; other destinations cannot.', 'bushido-almost-famous')
          })]
        }), showYouTubeField && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-ad-youtube",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('YouTube Video', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-ad-youtube",
            type: "text",
            value: ad.youtubeUrl,
            onChange: e => setAdField('youtubeUrl', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('https://www.youtube.com/watch?v=… or a video ID', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video Views campaigns on Google run as YouTube ads and need a YouTube-hosted video.', 'bushido-almost-famous')
          })]
        }), showPixelField && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            htmlFor: "af-ad-pixel",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Tracking Pixel ID', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
            id: "af-ad-pixel",
            type: "text",
            value: ad.pixelId,
            onChange: e => setAdField('pixelId', e.target.value),
            placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('e.g. 1234567890', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Conversions campaigns on Meta and TikTok need a tracking pixel to attribute results.', 'bushido-almost-famous')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("span", {
            className: "af-form-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Creatives', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "af-help-text",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Select the creative assets for the ad. The first video and image you pick become the ad media, a second image becomes the logo/companion image, and the first audio asset powers Spotify. Google needs a landscape image (e.g. 1200×628); Spotify needs a square one (at least 600×600).', 'bushido-almost-famous')
          }), creativeChoices]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-form-actions",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            type: "submit",
            className: "af-btn af-btn-primary",
            disabled: submitting,
            children: submitting ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Creating…', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Create Campaign', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            type: "button",
            className: "af-btn af-btn-secondary",
            onClick: () => navigate('#/'),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cancel', 'bushido-almost-famous')
          })]
        })]
      })
    })]
  });
}

/***/ },

/***/ "./src/public-portal/views/Dashboard.js"
/*!**********************************************!*\
  !*** ./src/public-portal/views/Dashboard.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   Dashboard: () => (/* binding */ Dashboard)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Dashboard — campaign list with search, filter, and action buttons.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Campaign dashboard.
 */

function Dashboard({
  navigate
}) {
  const manage = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.canManage)();
  const [campaigns, setCampaigns] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [search, setSearch] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [statusFilter, setStatusFilter] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const load = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setLoading(true);
    const params = {};
    if (search) {
      params.search = search;
    }
    if (statusFilter) {
      params.status = statusFilter;
    }
    _api__WEBPACK_IMPORTED_MODULE_2__.api.listCampaigns(params).then(res => {
      setCampaigns((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapCollection)(res));
      setLoading(false);
    }).catch(err => {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load campaigns', 'bushido-almost-famous'));
      setLoading(false);
    });
  }, [search, statusFilter]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    load();
  }, [load]);
  const handleSearch = e => {
    e.preventDefault();
    load();
  };
  if (loading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-loading",
      role: "status",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading campaigns…', 'bushido-almost-famous')
    });
  }
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      role: "alert",
      children: error
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-page-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaigns', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Manage your ad campaigns', 'bushido-almost-famous')
        })]
      }), manage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
        className: "af-btn af-btn-primary",
        onClick: () => navigate('#/campaigns/new'),
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('+ New Campaign', 'bushido-almost-famous')
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("form", {
      className: "af-toolbar",
      onSubmit: handleSearch,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
        type: "text",
        placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Search campaigns…', 'bushido-almost-famous'),
        value: search,
        onChange: e => setSearch(e.target.value)
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("select", {
        value: statusFilter,
        onChange: e => setStatusFilter(e.target.value),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('All statuses', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "DRAFT",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Draft', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "ACTIVE",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Active', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "PAUSED",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Paused', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "COMPLETED",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Completed', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("option", {
          value: "ARCHIVED",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Archived', 'bushido-almost-famous')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
        type: "submit",
        className: "af-btn af-btn-secondary af-btn-sm",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Search', 'bushido-almost-famous')
      })]
    }), campaigns.length === 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-empty",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-empty-icon",
        "aria-hidden": "true",
        children: "\uD83D\uDCE2"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
        children: manage ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No campaigns found. Create your first campaign to get started.', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No campaigns yet.', 'bushido-almost-famous')
      }), manage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
        className: "af-btn af-btn-primary",
        onClick: () => navigate('#/campaigns/new'),
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Create Campaign', 'bushido-almost-famous')
      })]
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-campaign-grid",
      children: campaigns.map(c => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "af-card af-campaign-card",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-card-header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            type: "button",
            className: "af-campaign-name af-link-button",
            onClick: () => navigate(`#/campaigns/${c.id}`),
            children: c.name || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Untitled Campaign', 'bushido-almost-famous')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_utils__WEBPACK_IMPORTED_MODULE_3__.StatusBadge, {
            status: c.status
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
          className: "af-card-body",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
            className: "af-campaign-meta",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("span", {
              children: ["\uD83D\uDCB0", ' ', (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(c.budgetTotal || c.budgetDaily, c.currency || 'USD')]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("span", {
              children: ["\uD83D\uDCC5 ", (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatDate)(c.createdAt)]
            }), c.objective && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("span", {
              children: ["\uD83C\uDFAF", ' ', c.objective.replace(/_/g, ' ').toLowerCase()]
            })]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
          className: "af-card-footer",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            className: "af-btn af-btn-secondary af-btn-sm",
            onClick: () => navigate(`#/campaigns/${c.id}`),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('View', 'bushido-almost-famous')
          }), manage && c.status === 'DRAFT' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            className: "af-btn af-btn-success af-btn-sm",
            onClick: () => navigate(`#/campaigns/${c.id}/checkout`),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Checkout', 'bushido-almost-famous')
          }), c.status === 'ACTIVE' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
            className: "af-btn af-btn-secondary af-btn-sm",
            onClick: () => navigate(`#/campaigns/${c.id}/analytics`),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Analytics', 'bushido-almost-famous')
          })]
        })]
      }, c.id))
    })]
  });
}

/***/ },

/***/ "./src/public-portal/views/Payments.js"
/*!*********************************************!*\
  !*** ./src/public-portal/views/Payments.js ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   Payments: () => (/* binding */ Payments)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../api */ "./src/public-portal/api.js");
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../utils */ "./src/public-portal/utils.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Payments list view with refund action.
 * @param {Object}   root0          Component properties.
 * @param {Function} root0.navigate Portal navigation callback.
 * @return {JSX.Element} Payments view.
 */

function Payments({
  navigate
}) {
  const [payments, setPayments] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [refunding, setRefunding] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [confirming, setConfirming] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [reason, setReason] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [refundError, setRefundError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    _api__WEBPACK_IMPORTED_MODULE_2__.api.listPayments().then(res => {
      setPayments((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapCollection)(res));
      setLoading(false);
    }).catch(err => {
      setError(err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load payments', 'bushido-almost-famous'));
      setLoading(false);
    });
  }, []);
  const startRefund = paymentId => {
    setRefundError(null);
    setReason('');
    setConfirming(paymentId);
  };
  const submitRefund = async paymentId => {
    setRefundError(null);
    setRefunding(paymentId);
    try {
      await _api__WEBPACK_IMPORTED_MODULE_2__.api.refundPayment(paymentId, {
        reason: reason.trim() || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('User requested refund', 'bushido-almost-famous')
      });
      const res = await _api__WEBPACK_IMPORTED_MODULE_2__.api.listPayments();
      setPayments((0,_api__WEBPACK_IMPORTED_MODULE_2__.unwrapCollection)(res));
      setConfirming(null);
    } catch (err) {
      setRefundError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: refund request error message. */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to request refund: %s', 'bushido-almost-famous'), err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Unknown error', 'bushido-almost-famous')));
    } finally {
      setRefunding(null);
    }
  };
  if (loading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-loading",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading payments…', 'bushido-almost-famous')
    });
  }
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      children: error
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-page-header",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Payments', 'bushido-almost-famous')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('View your payment history', 'bushido-almost-famous')
        })]
      })
    }), refundError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-alert af-alert-error",
      role: "alert",
      children: refundError
    }), payments.length === 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "af-empty",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-empty-icon",
        "aria-hidden": "true",
        children: "\uD83D\uDCB3"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No payments yet. Payments appear here after campaign checkout.', 'bushido-almost-famous')
      })]
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "af-card",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
        className: "af-table-wrap",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("table", {
          className: "af-table",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("thead", {
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Date', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Campaign', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Amount', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Fee', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Total', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Status', 'bushido-almost-famous')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Actions', 'bushido-almost-famous')
              })]
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("tbody", {
            children: payments.map(p => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatDate)(p.createdAt)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: p.campaignId ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
                  type: "button",
                  className: "af-link-button",
                  onClick: () => navigate(`#/campaigns/${p.campaignId}`),
                  children: p.campaignName || p.campaignId.substring(0, 8)
                }) : '—'
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(p.amount, p.currency || 'USD')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(p.serviceFee, p.currency || 'USD')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                style: {
                  fontWeight: 600
                },
                children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.formatCurrency)(p.totalCharged, p.currency || 'USD')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_utils__WEBPACK_IMPORTED_MODULE_3__.StatusBadge, {
                  status: p.status
                })
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("td", {
                children: [p.status === 'PAID' && confirming !== p.id && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
                  className: "af-btn af-btn-danger af-btn-sm",
                  onClick: () => startRefund(p.id),
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Refund', 'bushido-almost-famous')
                }), p.status === 'PAID' && confirming === p.id && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
                  className: "af-refund-form",
                  style: {
                    display: 'flex',
                    gap: '6px',
                    alignItems: 'center',
                    flexWrap: 'wrap'
                  },
                  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
                    className: "screen-reader-text",
                    htmlFor: `af-refund-reason-${p.id}`,
                    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Refund reason', 'bushido-almost-famous')
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("input", {
                    id: `af-refund-reason-${p.id}`,
                    type: "text",
                    placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Reason (optional)', 'bushido-almost-famous'),
                    value: reason,
                    onChange: e => setReason(e.target.value),
                    disabled: refunding === p.id
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
                    className: "af-btn af-btn-danger af-btn-sm",
                    onClick: () => submitRefund(p.id),
                    disabled: refunding === p.id,
                    children: refunding === p.id ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Requesting…', 'bushido-almost-famous') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Confirm refund', 'bushido-almost-famous')
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
                    className: "af-btn af-btn-secondary af-btn-sm",
                    onClick: () => setConfirming(null),
                    disabled: refunding === p.id,
                    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cancel', 'bushido-almost-famous')
                  })]
                })]
              })]
            }, p.id))
          })]
        })
      })
    })]
  });
}

/***/ },

/***/ "react/jsx-runtime"
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
(module) {

module.exports = window["ReactJSXRuntime"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/dom-ready"
/*!**********************************!*\
  !*** external ["wp","domReady"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["domReady"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!************************************!*\
  !*** ./src/public-portal/index.js ***!
  \************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/dom-ready */ "@wordpress/dom-ready");
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./utils */ "./src/public-portal/utils.js");
/* harmony import */ var _views_Dashboard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./views/Dashboard */ "./src/public-portal/views/Dashboard.js");
/* harmony import */ var _views_CreateCampaign__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./views/CreateCampaign */ "./src/public-portal/views/CreateCampaign.js");
/* harmony import */ var _views_CampaignDetail__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./views/CampaignDetail */ "./src/public-portal/views/CampaignDetail.js");
/* harmony import */ var _views_CampaignAnalytics__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./views/CampaignAnalytics */ "./src/public-portal/views/CampaignAnalytics.js");
/* harmony import */ var _views_Checkout__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./views/Checkout */ "./src/public-portal/views/Checkout.js");
/* harmony import */ var _views_Payments__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./views/Payments */ "./src/public-portal/views/Payments.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__);











function Nav({
  view,
  navigate,
  manage
}) {
  const link = (hash, label, key) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("a", {
    href: hash,
    className: view === key ? 'active' : '',
    "aria-current": view === key ? 'page' : undefined,
    onClick: event => {
      event.preventDefault();
      navigate(hash);
    },
    children: label
  }, key);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsxs)("nav", {
    className: "af-portal-nav",
    "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Bushido Almost Famous portal', 'bushido-almost-famous'),
    children: [link('#/', '📢 ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Campaigns', 'bushido-almost-famous'), 'dashboard'), manage && link('#/campaigns/new', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('+ New', 'bushido-almost-famous'), 'create'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("span", {
      className: "af-nav-spacer"
    }), manage && link('#/payments', '💳 ' + (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Payments', 'bushido-almost-famous'), 'payments')]
  });
}

/**
 * Shown to visitors who are not signed in (and not in demo mode). The portal
 * is an authenticated console — it never renders campaign data to anonymous
 * visitors, which would expose the owner's budgets and spend.
 */
function SignInGate() {
  const config = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.portalConfig)();
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("div", {
    className: "af-portal",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsxs)("div", {
      className: "af-card af-portal-gate",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("h2", {
        children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('signInTitle', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Sign in to manage campaigns', 'bushido-almost-famous'))
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("p", {
        children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('signInBody', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('The Bushido Almost Famous portal is available to signed-in team members.', 'bushido-almost-famous'))
      }), config.loginUrl && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("a", {
        className: "af-btn af-btn-primary",
        href: config.loginUrl,
        children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('signInCta', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Sign in', 'bushido-almost-famous'))
      })]
    })
  });
}
function App() {
  const {
    view,
    id,
    navigate
  } = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.useRouter)();
  const config = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.portalConfig)();
  const manage = (0,_utils__WEBPACK_IMPORTED_MODULE_3__.canManage)();

  // Authenticated console: anonymous visitors get a sign-in prompt, never
  // campaign data. Demo mode bypasses this with local fixtures.
  if (!(0,_utils__WEBPACK_IMPORTED_MODULE_3__.canView)()) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(SignInGate, {});
  }

  // Guard management-only routes against view-only users (defense in depth —
  // the nav already hides them, but a pasted #hash must not render a form
  // whose writes the server will reject).
  const manageOnly = ['create', 'checkout', 'payments'];
  const effectiveView = !manage && manageOnly.includes(view) ? 'dashboard' : view;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsxs)("div", {
    className: "af-portal",
    children: [(0,_utils__WEBPACK_IMPORTED_MODULE_3__.isDemo)() && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("div", {
      className: "af-alert af-alert-info",
      role: "status",
      children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('demoModeNotice', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Demo mode is enabled.', 'bushido-almost-famous'))
    }), !(0,_utils__WEBPACK_IMPORTED_MODULE_3__.isDemo)() && !config.hasApiKey && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("div", {
      className: "af-alert af-alert-warning",
      role: "status",
      children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('configurationRequired', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Configure an API key to use the portal.', 'bushido-almost-famous'))
    }), !manage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)("div", {
      className: "af-alert af-alert-info",
      role: "status",
      children: (0,_utils__WEBPACK_IMPORTED_MODULE_3__.t)('readOnlyNotice', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('You have view-only access. Ask an administrator for campaign-management permissions to make changes.', 'bushido-almost-famous'))
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(Nav, {
      view: effectiveView,
      navigate: navigate,
      manage: manage
    }), effectiveView === 'dashboard' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_Dashboard__WEBPACK_IMPORTED_MODULE_4__.Dashboard, {
      navigate: navigate
    }), effectiveView === 'create' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_CreateCampaign__WEBPACK_IMPORTED_MODULE_5__.CreateCampaign, {
      navigate: navigate
    }), effectiveView === 'detail' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_CampaignDetail__WEBPACK_IMPORTED_MODULE_6__.CampaignDetail, {
      id: id,
      navigate: navigate
    }), effectiveView === 'analytics' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_CampaignAnalytics__WEBPACK_IMPORTED_MODULE_7__.CampaignAnalytics, {
      id: id,
      navigate: navigate
    }), effectiveView === 'checkout' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_Checkout__WEBPACK_IMPORTED_MODULE_8__.Checkout, {
      id: id,
      navigate: navigate
    }), effectiveView === 'payments' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(_views_Payments__WEBPACK_IMPORTED_MODULE_9__.Payments, {
      navigate: navigate
    })]
  });
}
_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1___default()(() => {
  const el = document.getElementById('af-public-portal');
  if (el) {
    (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.render)(/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_10__.jsx)(App, {}), el);
  }
});
})();

/******/ })()
;
//# sourceMappingURL=public-portal.js.map