<!-- بخش فوتر: بستن تگ‌های باز شده و اسکریپت‌های نهایی -->
</main>
</div>
</div>

<!-- سفارشی‌سازی برای فعال‌سازی منوی کشویی با هوشمندی بیشتر (اختیاری) -->
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // بستن منو با کلیک بیرون
        var dropdowns = document.querySelectorAll('.dropdown-toggle');
        dropdowns.forEach(function(dropdown) {
            dropdown.addEventListener('click', function(e) {
                var parentLi = this.closest('.dropdown');
                if(parentLi.classList.contains('show')) {
                    // اگر باز بود، فعلاً کاری نکن (Bootstrap خودش مدیریت می‌کند)
                }
            });
        });
    });
</script>
</body>
</html>