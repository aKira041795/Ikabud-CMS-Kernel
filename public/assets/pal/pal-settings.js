/**
 * PAL Settings JS — inline category/unit/team-lead/supplier creation helpers.
 */
(function () {
  'use strict';

  window.addCategory = function (type, inputId) {
    var name = document.getElementById(inputId).value.trim();
    if (!name) { window.showToast('Enter a name', 'error'); return; }
    window.ajaxPost('/api/v1/project-audit-ledger/settings/categories', 'type=' + encodeURIComponent(type) + '&name=' + encodeURIComponent(name), 'Added');
  };

  window.addUnit = function () {
    var name = document.getElementById('new-unit-name').value.trim();
    if (!name) { window.showToast('Enter a name', 'error'); return; }
    var abbr = document.getElementById('new-unit-abbr').value.trim();
    window.ajaxPost('/api/v1/project-audit-ledger/settings/categories', 'type=unit&name=' + encodeURIComponent(name) + '&abbreviation=' + encodeURIComponent(abbr), 'Added');
  };

  window.addTeamLead = function () {
    var name = document.getElementById('new-tl-name').value.trim();
    if (!name) { window.showToast('Enter a name', 'error'); return; }
    var contact = document.getElementById('new-tl-contact').value.trim();
    var email = document.getElementById('new-tl-email').value.trim();
    window.ajaxPost('/api/v1/project-audit-ledger/settings/categories', 'type=team_lead&name=' + encodeURIComponent(name) + '&contact_number=' + encodeURIComponent(contact) + '&email=' + encodeURIComponent(email), 'Added');
  };

  window.addInvLocation = function () {
    var name = document.getElementById('new-loc-name').value.trim();
    if (!name) { window.showToast('Enter a name', 'error'); return; }
    var desc = document.getElementById('new-loc-desc').value.trim();
    window.ajaxPost('/api/v1/project-audit-ledger/settings/categories', 'type=inventory_location&name=' + encodeURIComponent(name) + '&description=' + encodeURIComponent(desc), 'Added');
  };

  window.addSupplier = function () {
    var name = document.getElementById('new-sup-name').value.trim();
    if (!name) { window.showToast('Enter a name', 'error'); return; }
    var cp = document.getElementById('new-sup-cp').value.trim();
    var email = document.getElementById('new-sup-email').value.trim();
    window.ajaxPost('/api/v1/project-audit-ledger/settings/suppliers', 'name=' + encodeURIComponent(name) + '&contact_person=' + encodeURIComponent(cp) + '&email=' + encodeURIComponent(email), 'Added');
  };

  window.toggleStatus = function (type, id) {
    var csrfInput = document.querySelector('input[name="_token"]');
    var body = 'type=' + encodeURIComponent(type) + (csrfInput ? '&' + csrfInput.name + '=' + encodeURIComponent(csrfInput.value) : '');
    fetch('/api/v1/project-audit-ledger/settings/toggle/' + id, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { window.showToast('Updated'); setTimeout(function () { location.reload(); }, 400); }
        else { window.showToast(d.error || 'Failed', 'error'); }
      })
      .catch(function () { window.showToast('Request failed', 'error'); });
  };

})();
