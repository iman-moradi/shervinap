<?php
$page_title = 'تاریخچه دستگاه‌های مشتری';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}
?>
<div class="card">
    <div class="card-header">
        <h5>جستجوی تاریخچه مشتری</h5>
        <input type="text" id="searchMobile" class="form-control" placeholder="شماره موبایل مشتری را وارد کنید..." autocomplete="off">
        <div id="searchStatus" class="small text-muted mt-1"></div>
    </div>
    <div class="card-body">
        <div id="loading" style="display:none;" class="alert alert-info">در حال جستجو...</div>
        <div id="historyResult"></div>
    </div>
</div>

<script>
$(document).ready(function(){
    var searchInput = $('#searchMobile');
    var loading = $('#loading');
    var historyResult = $('#historyResult');
    var searchStatus = $('#searchStatus');
    var searchTimeout = null;
    
    function performSearch() {
        var mobile = searchInput.val().trim();
        if (mobile.length === 0) {
            historyResult.html('<div class="alert alert-info">شماره موبایل را وارد کنید</div>');
            searchStatus.text('');
            return;
        }
        if (mobile.length < 5) {
            searchStatus.text('حداقل 5 رقم وارد کنید');
            return;
        }
        searchStatus.text('');
        loading.show();
        
        $.ajax({
            url: '../../ajax_search.php',
            type: 'GET',
            data: { type: 'customer_history', query: mobile },
            dataType: 'json',
            success: function(data) {
                loading.hide();
                if (data.length === 0) {
                    historyResult.html('<div class="alert alert-warning">هیچ مشتری با این شماره یافت نشد</div>');
                    return;
                }
                var html = '';
                for (var i = 0; i < data.length; i++) {
                    var cust = data[i];
                    html += '<div class="card mb-3">';
                    html += '<div class="card-header bg-info text-white"><strong>' + escapeHtml(cust.fullname) + '</strong> - ' + escapeHtml(cust.mobile) + '</div>';
                    html += '<div class="card-body">';
                    if (cust.tickets.length === 0) {
                        html += '<p>هیچ دستگاه ثبت شده‌ای برای این مشتری وجود ندارد.</p>';
                    } else {
                        html += '<table class="table table-sm table-bordered"><thead><tr><th>شماره پذیرش</th><th>دستگاه</th><th>تاریخ پذیرش</th><th>وضعیت</th><th>هزینه</th><th>عملیات</th></tr></thead><tbody>';
                        for (var j = 0; j < cust.tickets.length; j++) {
                            var t = cust.tickets[j];
                            html += '<tr>';
                            html += '<td>' + escapeHtml(t.ticket_no) + '</td>';
                            html += '<td>' + escapeHtml(t.device_type) + '</td>';
                            html += '<td>' + escapeHtml(t.received_date_sh) + '</td>';
                            html += '<td>' + escapeHtml(t.status) + '</td>';
                            html += '<td>' + formatToman(t.total_cost) + '</td>';
                            html += '<td><a href="view.php?id=' + t.id + '" class="btn btn-sm btn-info">مشاهده</a></td>';
                            html += '</tr>';
                        }
                        html += '</tbody></table>';
                    }
                    html += '</div></div>';
                }
                historyResult.html(html);
            },
            error: function() {
                loading.hide();
                historyResult.html('<div class="alert alert-danger">خطا در ارتباط با سرور</div>');
            }
        });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function formatToman(amount) {
        if (!amount) return '0 تومان';
        return new Intl.NumberFormat().format(amount) + ' تومان';
    }
    
    searchInput.on('keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 400);
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>