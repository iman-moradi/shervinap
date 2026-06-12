<?php
$page_title = 'تاریخچه دستگاه‌های مشتری';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}
?>

<style>
    :root {
        --primary-color: #4361ee;
        --secondary-color: #3f37c9;
        --success-color: #4caf50;
        --warning-color: #ff9800;
        --danger-color: #f44336;
        --info-color: #2196f3;
        --dark-color: #2b2d42;
        --light-color: #f8f9fa;
    }

    /* فیلترهای پیشرفته */
    .advanced-filters {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .filter-card {
        background: rgba(255,255,255,0.95);
        border-radius: 15px;
        padding: 15px;
        transition: all 0.3s ease;
    }
    
    .filter-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .filter-label {
        font-weight: 600;
        color: var(--dark-color);
        margin-bottom: 8px;
        display: block;
        font-size: 0.9rem;
    }
    
    .filter-input {
        border-radius: 10px;
        border: 2px solid #e0e0e0;
        transition: all 0.3s ease;
        padding: 8px 12px;
        width: 100%;
    }
    
    .filter-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        outline: none;
    }
    
    /* کارت‌های آماری */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.15);
    }
    
    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        margin-bottom: 10px;
    }
    
    .stat-label {
        color: #666;
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .stat-icon {
        font-size: 2rem;
        opacity: 0.2;
        position: absolute;
        bottom: 10px;
        right: 10px;
    }
    
    /* کارت‌های تاریخچه */
    .history-card {
        background: white;
        border-radius: 15px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
    }
    
    .history-card:hover {
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    }
    
    .customer-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 15px 20px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .customer-header:hover {
        filter: brightness(1.05);
    }
    
    .customer-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .customer-name {
        font-size: 1.1rem;
        font-weight: 600;
    }
    
    .customer-mobile {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .ticket-count {
        background: rgba(255,255,255,0.2);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
    }
    
    .ticket-item {
        border-bottom: 1px solid #f0f0f0;
        transition: all 0.3s ease;
    }
    
    .ticket-item:hover {
        background: #f8f9fa;
        transform: translateX(-5px);
    }
    
    .ticket-item:last-child {
        border-bottom: none;
    }
    
    /* وضعیت‌های مختلف */
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .status-pending { background: #fff3e0; color: #ff9800; }
    .status-in-progress { background: #e3f2fd; color: #2196f3; }
    .status-completed { background: #e8f5e9; color: #4caf50; }
    .status-delayed { background: #ffebee; color: #f44336; }
    .status-delivered { background: #e0f2f1; color: #009688; }
    
    /* مودال مدرن */
    .modal-modern .modal-content {
        border-radius: 20px;
        border: none;
        overflow: hidden;
    }
    
    .modal-modern .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        padding: 20px;
    }
    
    .modal-modern .modal-body {
        padding: 25px;
        max-height: 70vh;
        overflow-y: auto;
    }
    
    .detail-section {
        margin-bottom: 25px;
    }
    
    .detail-title {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }
    
    .detail-item {
        padding: 10px;
        background: #f8f9fa;
        border-radius: 10px;
    }
    
    .detail-label {
        font-size: 0.8rem;
        color: #666;
        display: block;
        margin-bottom: 5px;
    }
    
    .detail-value {
        font-weight: 600;
        color: var(--dark-color);
    }
    
    /* دکمه‌ها */
    .btn-gradient {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 10px;
        transition: all 0.3s ease;
    }
    
    .btn-gradient:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(67,97,238,0.3);
        color: white;
    }
    
    /* انیمیشن لودینگ */
    .loading-spinner {
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid var(--primary-color);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* حالت خالی */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 20px;
    }
    
    .empty-state i {
        font-size: 4rem;
        color: #ccc;
        margin-bottom: 20px;
    }
    
    /* دکمه پرینت */
    .print-btn {
        position: fixed;
        bottom: 20px;
        left: 20px;
        z-index: 1000;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
    }
    
    .print-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    }
    
    @media print {
        .sidebar, .advanced-filters, .stats-grid, .print-btn, .btn-gradient, .no-print {
            display: none !important;
        }
        .history-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .customer-header {
            background: #f0f0f0 !important;
            color: black !important;
        }
    }
    
    /* پاسخگو */
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .customer-info {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
</style>

<!-- فیلترهای پیشرفته -->
<div class="advanced-filters">
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-user"></i> نام مشتری
                </label>
                <input type="text" id="filterName" class="filter-input" placeholder="جستجو بر اساس نام...">
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-mobile-alt"></i> شماره موبایل
                </label>
                <input type="text" id="filterMobile" class="filter-input" placeholder="جستجو بر اساس موبایل...">
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-microchip"></i> سریال دستگاه
                </label>
                <input type="text" id="filterSerial" class="filter-input" placeholder="سریال دستگاه...">
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-ticket-alt"></i> شماره پذیرش
                </label>
                <input type="text" id="filterTicketNo" class="filter-input" placeholder="شماره پذیرش...">
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-flag-checkered"></i> وضعیت
                </label>
                <select id="filterStatus" class="filter-input">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending">⏳ در انتظار بررسی</option>
                    <option value="in_progress">🔧 در حال تعمیر</option>
                    <option value="completed">✅ تکمیل شده</option>
                    <option value="delayed">⚠️ تأخیر در تحویل</option>
                    <option value="delivered">📦 تحویل داده شده</option>
                </select>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-calendar-alt"></i> از تاریخ
                </label>
                <input type="date" id="filterDateFrom" class="filter-input">
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">
                    <i class="fas fa-calendar-alt"></i> تا تاریخ
                </label>
                <input type="date" id="filterDateTo" class="filter-input">
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="filter-card">
                <label class="filter-label">&nbsp;</label>
                <button class="btn-gradient w-100" onclick="resetFilters()">
                    <i class="fas fa-redo-alt"></i> بازنشانی فیلترها
                </button>
            </div>
        </div>
    </div>
</div>

<!-- آمار -->
<div class="stats-grid" id="statsGrid" style="display: none;">
    <div class="stat-card" onclick="filterByStatus('all')">
        <div class="stat-number" id="totalTickets">0</div>
        <div class="stat-label">کل دستگاه‌ها</div>
        <i class="fas fa-chart-line stat-icon"></i>
    </div>
    <div class="stat-card" onclick="filterByStatus('completed')">
        <div class="stat-number" id="completedCount">0</div>
        <div class="stat-label">تکمیل شده</div>
        <i class="fas fa-check-circle stat-icon"></i>
    </div>
    <div class="stat-card" onclick="filterByStatus('in_progress')">
        <div class="stat-number" id="inProgressCount">0</div>
        <div class="stat-label">در حال تعمیر</div>
        <i class="fas fa-cogs stat-icon"></i>
    </div>
    <div class="stat-card">
        <div class="stat-number" id="totalCost">0</div>
        <div class="stat-label">کل هزینه (تومان)</div>
        <i class="fas fa-dollar-sign stat-icon"></i>
    </div>
</div>

<!-- دکمه پرینت -->
<button class="print-btn no-print" onclick="printReport()">
    <i class="fas fa-print"></i>
</button>

<!-- نتیجه جستجو -->
<div id="historyResult"></div>

<!-- مودال جزئیات -->
<div class="modal fade modal-modern" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle"></i> جزئیات کامل دستگاه
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center">
                    <div class="loading-spinner"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                <button type="button" class="btn-gradient" onclick="printTicketDetail()">
                    <i class="fas fa-print"></i> پرینت
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentData = [];
let searchTimeout = null;
let currentDetailTicket = null;

$(document).ready(function() {
    // رویدادهای جستجو
    $('#filterName, #filterMobile, #filterSerial, #filterTicketNo, #filterStatus, #filterDateFrom, #filterDateTo').on('input change', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performAdvancedSearch, 500);
    });
    
    // جستجوی اولیه از URL
    const urlParams = new URLSearchParams(window.location.search);
    const mobileParam = urlParams.get('mobile');
    if (mobileParam) {
        $('#filterMobile').val(mobileParam);
        performAdvancedSearch();
    }
});

function performAdvancedSearch() {
    const filters = {
        name: $('#filterName').val().trim(),
        mobile: $('#filterMobile').val().trim(),
        serial: $('#filterSerial').val().trim(),
        ticket_no: $('#filterTicketNo').val().trim(),
        status: $('#filterStatus').val(),
        date_from: $('#filterDateFrom').val(),
        date_to: $('#filterDateTo').val()
    };
    
    // بررسی حداقل یک فیلتر
    const hasFilter = Object.values(filters).some(v => v && v !== '');
    if (!hasFilter) {
        $('#historyResult').html(`
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h4>جستجو کنید</h4>
                <p>لطفاً حداقل یکی از فیلترهای جستجو را پر کنید</p>
            </div>
        `);
        $('#statsGrid').hide();
        return;
    }
    
    showLoading();
    
    $.ajax({
        url: '../../ajax_search.php',
        type: 'GET',
        data: { 
            type: 'customer_history_advanced',
            ...filters
        },
        dataType: 'json',
        success: function(response) {
            hideLoading();
            
            if (response.error) {
                showError(response.error);
                return;
            }
            
            if (!response.customers || response.customers.length === 0) {
                showEmpty();
                return;
            }
            
            currentData = response.customers;
            displayResults(response.customers);
            updateStats(response.customers);
            $('#statsGrid').show();
        },
        error: function() {
            hideLoading();
            showError('خطا در ارتباط با سرور');
        }
    });
}

function displayResults(customers) {
    let html = '';
    
    for (const customer of customers) {
        html += `
            <div class="history-card">
                <div class="customer-header" onclick="toggleTickets(${customer.id})">
                    <div class="customer-info">
                        <div>
                            <div class="customer-name">
                                <i class="fas fa-user-circle"></i> ${escapeHtml(customer.fullname)}
                            </div>
                            <div class="customer-mobile">
                                <i class="fas fa-mobile-alt"></i> ${escapeHtml(customer.mobile)}
                            </div>
                        </div>
                        <div class="ticket-count">
                            <i class="fas fa-ticket-alt"></i> ${customer.tickets.length} دستگاه
                            <i class="fas fa-chevron-down ms-2" id="chevron-${customer.id}"></i>
                        </div>
                    </div>
                </div>
                <div id="tickets-${customer.id}" style="display: none;">
        `;
        
        for (const ticket of customer.tickets) {
            const statusClass = getStatusClass(ticket.status);
            const statusText = getStatusText(ticket.status);
            
            html += `
                <div class="ticket-item p-3" onclick="showTicketDetail(${ticket.id})" style="cursor: pointer;">
                    <div class="row align-items-center">
                        <div class="col-md-2">
                            <strong>${escapeHtml(ticket.ticket_no)}</strong>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-microchip"></i> ${escapeHtml(ticket.device_type)}
                            ${ticket.device_model ? '<br><small class="text-muted">' + escapeHtml(ticket.device_model) + '</small>' : ''}
                        </div>
                        <div class="col-md-2">
                            <i class="fas fa-calendar"></i> ${escapeHtml(ticket.received_date_sh)}
                        </div>
                        <div class="col-md-2">
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                        <div class="col-md-2">
                            <i class="fas fa-money-bill"></i> ${formatToman(ticket.total_cost)}
                        </div>
                        <div class="col-md-1 text-end">
                            <i class="fas fa-chevron-left text-muted"></i>
                        </div>
                    </div>
                </div>
            `;
        }
        
        html += `
                </div>
            </div>
        `;
    }
    
    $('#historyResult').html(html);
}

function toggleTickets(customerId) {
    const ticketsDiv = $(`#tickets-${customerId}`);
    const chevron = $(`#chevron-${customerId}`);
    
    if (ticketsDiv.is(':visible')) {
        ticketsDiv.slideUp(300);
        chevron.removeClass('fa-chevron-up').addClass('fa-chevron-down');
    } else {
        ticketsDiv.slideDown(300);
        chevron.removeClass('fa-chevron-down').addClass('fa-chevron-up');
    }
}

function showTicketDetail(ticketId) {
    currentDetailTicket = ticketId;
    $('#detailModal').modal('show');
    
    $.ajax({
        url: '../../ajax_search.php',
        type: 'GET',
        data: { type: 'ticket_detail_advanced', id: ticketId },
        dataType: 'json',
        success: function(data) {
            if (data.error) {
                $('#modalBody').html(`<div class="alert alert-danger">${data.error}</div>`);
                return;
            }
            
            const ticket = data.ticket;
            const repairs = data.repairs || [];
            const parts = data.parts || [];
            
            let html = `
                <div class="detail-section">
                    <h6 class="detail-title">
                        <i class="fas fa-ticket-alt"></i> اطلاعات پذیرش
                    </h6>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">شماره پذیرش</span>
                            <div class="detail-value">${escapeHtml(ticket.ticket_no)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">وضعیت</span>
                            <div class="detail-value">
                                <span class="status-badge ${getStatusClass(ticket.status)}">${getStatusText(ticket.status)}</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">تاریخ پذیرش</span>
                            <div class="detail-value">${escapeHtml(ticket.received_date_sh)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">تاریخ تحویل</span>
                            <div class="detail-value">${escapeHtml(ticket.delivery_date_sh || 'در حال تعمیر')}</div>
                        </div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-title">
                        <i class="fas fa-microchip"></i> اطلاعات دستگاه
                    </h6>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">نوع دستگاه</span>
                            <div class="detail-value">${escapeHtml(ticket.device_type)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">مدل دستگاه</span>
                            <div class="detail-value">${escapeHtml(ticket.device_model || '-')}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">سریال دستگاه</span>
                            <div class="detail-value">${escapeHtml(ticket.serial_no || '-')}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">شرح عیب</span>
                            <div class="detail-value">${escapeHtml(ticket.fault_description || '-')}</div>
                        </div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-title">
                        <i class="fas fa-user"></i> اطلاعات مشتری
                    </h6>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">نام مشتری</span>
                            <div class="detail-value">${escapeHtml(ticket.customer_name)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">شماره تماس</span>
                            <div class="detail-value">${escapeHtml(ticket.customer_mobile)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">ایمیل</span>
                            <div class="detail-value">${escapeHtml(ticket.customer_email || '-')}</div>
                        </div>
                    </div>
                </div>
            `;
            
            if (repairs.length > 0) {
                html += `
                    <div class="detail-section">
                        <h6 class="detail-title">
                            <i class="fas fa-tools"></i> تعمیرات انجام شده
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>شرح عیب</th>
                                        <th>تعمیر انجام شده</th>
                                        <th>تاریخ</th>
                                        <th>تکنسین</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                for (const repair of repairs) {
                    html += `
                        <tr>
                            <td>${escapeHtml(repair.fault_description)}</td>
                            <td>${escapeHtml(repair.repair_description)}</td>
                            <td>${escapeHtml(repair.repair_date_sh)}</td>
                            <td>${escapeHtml(repair.technician_name || '-')}</td>
                        </tr>
                    `;
                }
                html += `</tbody></table></div>`;
            }
            
            if (parts.length > 0) {
                let totalParts = 0;
                html += `
                    <div class="detail-section">
                        <h6 class="detail-title">
                            <i class="fas fa-microchip"></i> قطعات تعویض شده
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>قطعه</th>
                                        <th>تعداد</th>
                                        <th>قیمت واحد</th>
                                        <th>جمع</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                for (const part of parts) {
                    const subtotal = part.quantity * part.price;
                    totalParts += subtotal;
                    html += `
                        <tr>
                            <td>${escapeHtml(part.product_name)}</td>
                            <td>${part.quantity}</td>
                            <td>${formatToman(part.price)}</td>
                            <td>${formatToman(subtotal)}</td>
                        </tr>
                    `;
                }
                html += `
                    <tr class="table-info">
                        <td colspan="3"><strong>جمع قطعات</strong></td>
                        <td><strong>${formatToman(totalParts)}</strong></td>
                    </tr>
                </tbody></table></div>`;
            }
            
            html += `
                <div class="detail-section">
                    <h6 class="detail-title">
                        <i class="fas fa-money-bill-wave"></i> اطلاعات مالی
                    </h6>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">هزینه نهایی</span>
                            <div class="detail-value text-success">${formatToman(ticket.total_cost)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">پیش‌پرداخت</span>
                            <div class="detail-value">${formatToman(ticket.deposit_amount || 0)}</div>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">باقیمانده</span>
                            <div class="detail-value ${(ticket.total_cost - (ticket.deposit_amount || 0)) > 0 ? 'text-danger' : 'text-success'}">
                                ${formatToman((ticket.total_cost || 0) - (ticket.deposit_amount || 0))}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            $('#modalBody').html(html);
        },
        error: function() {
            $('#modalBody').html('<div class="alert alert-danger">خطا در دریافت جزئیات</div>');
        }
    });
}

function updateStats(customers) {
    let totalTickets = 0;
    let totalCost = 0;
    let completedCount = 0;
    let inProgressCount = 0;
    
    for (const customer of customers) {
        for (const ticket of customer.tickets) {
            totalTickets++;
            totalCost += ticket.total_cost || 0;
            if (ticket.status === 'completed' || ticket.status === 'delivered') {
                completedCount++;
            }
            if (ticket.status === 'in_progress') {
                inProgressCount++;
            }
        }
    }
    
    $('#totalTickets').text(totalTickets);
    $('#totalCost').text(formatToman(totalCost));
    $('#completedCount').text(completedCount);
    $('#inProgressCount').text(inProgressCount);
}

function filterByStatus(status) {
    if (status === 'all') {
        $('#filterStatus').val('');
    } else {
        $('#filterStatus').val(status);
    }
    performAdvancedSearch();
}

function resetFilters() {
    $('#filterName, #filterMobile, #filterSerial, #filterTicketNo, #filterDateFrom, #filterDateTo').val('');
    $('#filterStatus').val('');
    performAdvancedSearch();
}

function printReport() {
    window.print();
}

function printTicketDetail() {
    if (currentDetailTicket) {
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>جزئیات دستگاه</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                <style>
                    body { font-family: Tahoma, sans-serif; padding: 20px; direction: rtl; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .detail-section { margin-bottom: 20px; }
                    .detail-title { font-weight: bold; border-bottom: 2px solid #333; margin-bottom: 10px; }
                    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                    th { background: #f0f0f0; }
                </style>
            </html>
            <body>
                ${$('#modalBody').html()}
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }
}

function getStatusClass(status) {
    const classes = {
        'pending': 'status-pending',
        'in_progress': 'status-in-progress',
        'completed': 'status-completed',
        'delayed': 'status-delayed',
        'delivered': 'status-delivered'
    };
    return classes[status] || 'status-pending';
}

function getStatusText(status) {
    const texts = {
        'pending': 'در انتظار بررسی',
        'in_progress': 'در حال تعمیر',
        'completed': 'تکمیل شده',
        'delayed': 'تأخیر در تحویل',
        'delivered': 'تحویل داده شده'
    };
    return texts[status] || status;
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
    if (!amount && amount !== 0) return '0 تومان';
    return new Intl.NumberFormat('fa-IR').format(amount) + ' تومان';
}

function showLoading() {
    $('#historyResult').html(`
        <div class="text-center p-5">
            <div class="loading-spinner"></div>
            <p class="mt-3">در حال جستجو...</p>
        </div>
    `);
}

function hideLoading() {
    // Loading removed, handled by display functions
}

function showError(message) {
    $('#historyResult').html(`
        <div class="empty-state">
            <i class="fas fa-exclamation-triangle text-danger"></i>
            <h4>خطا</h4>
            <p>${escapeHtml(message)}</p>
        </div>
    `);
    $('#statsGrid').hide();
}

function showEmpty() {
    $('#historyResult').html(`
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <h4>نتیجه‌ای یافت نشد</h4>
            <p>هیچ مشترکی با فیلترهای انتخاب شده پیدا نشد</p>
        </div>
    `);
    $('#statsGrid').hide();
}
</script>

<?php require_once '../../includes/footer.php'; ?>