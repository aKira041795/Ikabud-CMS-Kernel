/**
 * ikb-react-cases-table.js — React CasesTable Component
 *
 * Renders a cases table using React.createElememt (no JSX).
 * Registered as "CasesTable" in the ikb-react component registry.
 *
 * Load AFTER ikb-react.js and React/ReactDOM CDN.
 *
 * Dependencies:
 *   - React + ReactDOM (CDN)
 *   - ikb-react.js (mount script)
 */

(function () {
    'use strict';

    var h = React.createElement;
    var registry = window.__ikbReactComponents = window.__ikbReactComponents || {};

    registry.CasesTable = function (props) {
        var items = props.items || [];
        var baseUrl = props.base_url || '';

        var container = props.element;
        if (!container) return;

        function severityBadge(severity) {
            var map = {
                critical: 'bg-red-100 text-red-700',
                high: 'bg-orange-100 text-orange-700',
                medium: 'bg-amber-100 text-amber-700',
                low: 'bg-green-100 text-green-700'
            };
            var cls = map[severity] || 'bg-gray-100 text-gray-600';
            return h('span', { className: 'inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold ' + cls },
                (severity || '').charAt(0).toUpperCase() + (severity || '').slice(1)
            );
        }

        function statusBadge(status) {
            var map = {
                open: 'bg-blue-100 text-blue-700',
                in_progress: 'bg-indigo-100 text-indigo-700',
                on_hold: 'bg-amber-100 text-amber-700',
                closed: 'bg-gray-100 text-gray-600'
            };
            var cls = map[status] || 'bg-gray-100 text-gray-600';
            var label = status ? status.replace(/_/g, ' ') : '';
            label = label.replace(/\b\w/g, function (l) { return l.toUpperCase(); });
            return h('span', { className: 'inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold ' + cls }, label);
        }

        var headers = ['Student', 'Case #', 'Status', 'Severity', 'Category', 'College', 'Counselor', 'Updated'];
        var fields = ['student_name', 'case_number', '_status', '_severity', 'category', 'college_code', 'counselor_name', 'updated_at'];

        var table = h('div', { className: 'bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden' },
            h('table', { className: 'min-w-full divide-y divide-gray-200 text-sm' },
                h('thead', { className: 'bg-gray-50' },
                    h('tr', null,
                        headers.map(function (hdr) {
                            return h('th', {
                                key: hdr,
                                className: 'px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'
                            }, hdr);
                        })
                    )
                ),
                h('tbody', { className: 'bg-white divide-y divide-gray-200' },
                    items.length === 0
                        ? h('tr', null,
                            h('td', {
                                colSpan: headers.length,
                                className: 'px-6 py-12 text-center text-gray-500'
                            }, 'No cases found.')
                          )
                        : items.map(function (row, idx) {
                            return h('tr', {
                                key: row.id || idx,
                                className: 'hover:bg-gray-50 transition-colors cursor-pointer',
                                onClick: function () {
                                    if (row.id && baseUrl) {
                                        window.htmx && htmx.ajax('GET', baseUrl + '/pages/cases/' + row.id, {
                                            target: '#main-content'
                                        });
                                    }
                                }
                            }, fields.map(function (f) {
                                var val = f === '_status' ? statusBadge(row.status)
                                      : f === '_severity' ? severityBadge(row.severity)
                                      : row[f] || '\u2014';
                                return h('td', {
                                    key: f,
                                    className: 'px-6 py-4 whitespace-nowrap text-gray-700'
                                }, val);
                            }));
                        })
                )
            )
        );

        // Replace container contents
        container.innerHTML = '';
        container.appendChild(table);
    };
})();
