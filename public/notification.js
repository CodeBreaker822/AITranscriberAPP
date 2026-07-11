function showNotification(message, type = 'success') {
    const bgClass = type === 'success' ? 'bg-green-500' : 'bg-red-500';
    const notificationId = 'notification-' + Date.now();
    
    const notification = `
        <div id="${notificationId}" class="fixed ${bgClass} text-white px-6 py-3 rounded shadow-lg notification" style="z-index: 2147483647 !important; top: 20px !important; right: 20px !important; position: fixed !important; display: block !important; visibility: visible !important; opacity: 1 !important;">
            <div class="flex items-center justify-between">
                <span>${message}</span>
                <button onclick="closeNotification('${notificationId}')" class="ml-4 text-white hover:text-gray-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    `;
    
    $('body').append(notification);
    
    // Slide in from right
    setTimeout(() => {
        $('#' + notificationId).css({
            'transition': 'transform 0.3s ease-out',
            'transform': 'translateX(0)',
            'right': '20px'
        });
    }, 100);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        closeNotification(notificationId);
    }, 5000);
}

function closeNotification(notificationId) {
    $('#' + notificationId).css({
        'transform': 'translateX(100%)'
    });
    setTimeout(() => {
        $('#' + notificationId).remove();
    }, 300);
}
