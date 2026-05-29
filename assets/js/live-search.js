// یک نمونه ساده برای جستجوی زنده در جدول‌ها
$(document).ready(function(){
    $('.live-search').on('keyup', function(){
        var value = $(this).val().toLowerCase();
        $('.searchable-table tbody tr').filter(function(){
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
});