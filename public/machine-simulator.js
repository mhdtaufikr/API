'use strict';
const form = document.querySelector('#simulator');
const output = document.querySelector('#response');
const validation = document.querySelector('#validation');
const database = document.querySelector('#database');
const buttons = [...form.querySelectorAll('button')];
const initiallyDisabled = new Set(buttons.filter(button => button.disabled));
function syncMessage() {
  const date = document.querySelector('#d').value;
  const time = document.querySelector('#h').value;
  const seconds = document.querySelector('#seconds').value;
  const terminal = document.querySelector('#m').value;
  const direction = document.querySelector('#direction').value;
  const valid = /^\d{4}-\d{2}-\d{2}$/.test(date) && /^\d{2}:\d{2}$/.test(time) && /^\d{1,2}$/.test(seconds) && Number(seconds) <= 59 && /^\d{3}$/.test(terminal) && /^\d{2}$/.test(direction);
  document.querySelector('#cardno').value = valid ? `${terminal}0002T2120${date.replaceAll('-', '')}${time.replace(':', '')}${seconds.padStart(2, '0')}${direction}` : '';
  return valid;
}
const values = () => ({cardno: document.querySelector('#cardno').value, d: document.querySelector('#d').value.replaceAll('-', '/'), h: document.querySelector('#h').value, m: document.querySelector('#m').value});
form.addEventListener('input', syncMessage);
form.addEventListener('change', syncMessage);
syncMessage();
function target() {
  const url = new URL(form.dataset.endpoint, window.location.href);
  if (url.origin !== window.location.origin) throw new Error('Simulator receiver must be on the same local origin.');
  url.search = new URLSearchParams(values()).toString();
  document.querySelector('#request-url').textContent = url.href;
  return url;
}
async function inspect(requestId = '') {
  const url = new URL('receiver-inspector.php', window.location.href);
  if (requestId) url.searchParams.set('request_id', requestId);
  else { url.search = new URLSearchParams(values()).toString(); url.searchParams.set('parse_only', '1'); }
  const r = await fetch(url, {cache: 'no-store', signal: AbortSignal.timeout(12000)});
  const data = await r.json();
  validation.textContent = JSON.stringify({valid: data.valid, parsed: data.parsed, audit: data.audit, error: data.error}, null, 2);
  database.textContent = JSON.stringify({status: data.database_status ?? 'Not stored / no database lookup', record: data.record ?? null}, null, 2);
}
async function run(kind) {
  if (!syncMessage() || !form.reportValidity()) { output.textContent = 'Lengkapi tanggal, jam, detik, terminal 3 digit, dan arah 2 digit.'; return; }
  if ((kind === 'single' || kind === 'duplicate') && form.dataset.supported !== 'true') { output.textContent = 'Receiver requires PHP 7.4+. Open the compatible simulator using the link above.'; return; }
  buttons.forEach(b => { b.disabled = true; });
  output.textContent = 'Processing local request…';
  try {
    if (kind === 'parse') { await inspect(); output.textContent = 'Parse only; no receiver insert or Talenta request.'; return; }
    if (kind !== 'post') {
      for (let i = 0; i < (kind === 'duplicate' ? 2 : 1); i++) {
        const endpoint = new URL('simulator-submit.php', window.location.href);
        document.querySelector('#request-url').textContent = 'POST ' + endpoint.href;
        const response = await fetch(endpoint, {method: 'POST', cache: 'no-store', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({...values(), legacy_direction: document.querySelector('#legacy-direction')?.value ?? '', csrf: form.querySelector('[name="csrf"]').value}), signal: AbortSignal.timeout(25000)});
        const result = await response.json();
        output.textContent = JSON.stringify({http: response.status, ...result}, null, 2);
        const status = document.querySelector('#queue-result');
        status.textContent = result.queue?.message ?? result.message ?? result.reason ?? 'Belum masuk antrean. Tidak ada pengiriman ke Talenta.';
        status.className = 'alert mt-3 ' + (response.ok ? 'alert-success' : 'alert-danger');
        if (result.parsed) {
          validation.textContent = JSON.stringify(result.parsed, null, 2);
          database.textContent = JSON.stringify(result.database ?? {status: result.status, talenta_sent: false}, null, 2);
        } else if (result.request_id) await inspect(result.request_id);
        if (!response.ok) break;
      }
      return;
    }
    const url = target(); const results = [];
    for (let i = 0; i < (kind === 'duplicate' ? 2 : 1); i++) {
      const options = {method: kind === 'post' ? 'POST' : 'GET', cache: 'no-store', signal: AbortSignal.timeout(15000)};
      if (kind === 'post') { options.body = JSON.stringify(values()); options.headers = {'Content-Type':'application/json'}; }
      const response = await fetch(url, options);
      const result = {http: response.status, result: response.headers.get('X-Receiver-Result'), request_id: response.headers.get('X-Receiver-Request-Id'), body: await response.text()};
      results.push(result); output.textContent = JSON.stringify(results, null, 2);
      if (result.request_id) await inspect(result.request_id);
    }
  } catch (error) { output.textContent += '\n' + error.message; }
  finally { buttons.forEach(b => { b.disabled = initiallyDisabled.has(b); }); }
}
form.addEventListener('submit', event => { event.preventDefault(); run('single'); });
document.querySelector('#duplicate').addEventListener('click', () => run('duplicate'));
document.querySelector('#parse').addEventListener('click', () => run('parse'));
document.querySelector('#post').addEventListener('click', () => run('post'));
