document.querySelectorAll('[data-copy-url]').forEach(function (button) {
    button.addEventListener('click', async function () {
        var url = button.getAttribute('data-copy-url');
        try {
            if (!navigator.clipboard || !window.isSecureContext) { throw new Error('unavailable'); }
            await navigator.clipboard.writeText(url);
            button.textContent = '已复制';
            setTimeout(function () { button.textContent = '复制链接'; }, 1500);
        } catch (error) { window.prompt('请复制图片链接：', url); }
    });
});
document.querySelectorAll('.media-thumb img').forEach(function (img) {
    var showError = function () {
        img.hidden = true;
        var message = img.parentElement.querySelector('.media-image-error');
        if (message) { message.hidden = false; }
    };
    img.addEventListener('error', showError);
    if (img.complete && img.naturalWidth === 0) { showError(); }
});

var bulkChecks = Array.from(document.querySelectorAll('input[name="bulk_keys[]"]'));
var selectedCount = document.getElementById('selected-count');
function showSelectedCount() { if (selectedCount) { selectedCount.textContent = '已选 ' + bulkChecks.filter(function (box) { return box.checked && !box.disabled; }).length + ' 张'; } }
bulkChecks.forEach(function (box) { box.addEventListener('change', showSelectedCount); });
var selectUnused = document.getElementById('select-unused');
if (selectUnused) { selectUnused.addEventListener('click', function () { bulkChecks.forEach(function (box) { box.checked = !box.disabled && box.dataset.bulkCandidate === '1'; }); showSelectedCount(); }); }
var selectAll = document.getElementById('select-all');
if (selectAll) { selectAll.addEventListener('click', function () { bulkChecks.forEach(function (box) { box.checked = !box.disabled; }); showSelectedCount(); }); }
var clearSelected = document.getElementById('clear-selected');
if (clearSelected) { clearSelected.addEventListener('click', function () { bulkChecks.forEach(function (box) { box.checked = false; }); showSelectedCount(); }); }
var selectionForm = document.getElementById('bulk-selection');
if (selectionForm) { selectionForm.addEventListener('submit', function (event) { if (!bulkChecks.some(function (box) { return box.checked && !box.disabled; })) { event.preventDefault(); window.alert('请先选择本页需要删除的图片。'); } }); }
document.querySelectorAll('.scan-form').forEach(function (form) { form.addEventListener('submit', function () { var button = form.querySelector('button'); button.disabled = true; button.textContent = '正在检测，请稍候…'; }); });
var confirmForm = document.getElementById('bulk-confirm-form');
if (confirmForm && window.fetch && window.FormData) {
    confirmForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        var button = confirmForm.querySelector('button[type="submit"]');
        if (button.disabled) { return; }
        button.disabled = true;
        var progress = document.querySelector('.bulk-progress');
        var keys = Array.from(document.querySelectorAll('[data-confirm-key]')).map(function (item) { return item.dataset.confirmKey; });
        var done = 0;
        try {
            for (var offset = 0; offset < keys.length; offset += 3) {
                progress.textContent = (confirmForm.dataset.manual === '1' ? '正在删除所选图片，已完成 ' : '正在复核文章引用并删除，已完成 ') + done + ' / ' + keys.length + ' 张。';
                var body = new FormData(confirmForm); body.set('action', 'bulk_run');
                keys.slice(offset, offset + 3).forEach(function (key) { body.append('batch_keys[]', key); });
                var response = await fetch('manager.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (!response.headers.get('content-type') || response.headers.get('content-type').indexOf('application/json') === -1) { throw new Error('未收到有效结果，请刷新列表确认云端状态；未自动重试。'); }
                var result = await response.json();
                done += Array.isArray(result.deleted) ? result.deleted.length : 0;
                if (!response.ok || !result.ok || !Array.isArray(result.deleted) || result.deleted.length !== Math.min(3, keys.length - offset)) { throw new Error(result.error || '本批未全部完成，已停止后续删除。'); }
            }
            progress.textContent = '已完成：删除 ' + done + ' 张云端图片。本地副本和附件记录保留。请点击“取消 / 刷新列表”查看结果。';
            button.textContent = '处理完成';
        } catch (error) { progress.textContent = '已确认删除 ' + done + ' 张。操作已停止：' + error.message + ' 请刷新列表确认剩余图片状态。'; button.textContent = '已停止，请刷新列表'; }
    });
}
