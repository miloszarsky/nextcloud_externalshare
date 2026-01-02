(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('externalshare-form');
        const messageDiv = document.getElementById('externalshare-message');

        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            const data = {};
            for (const [key, value] of formData.entries()) {
                data[key] = value;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            fetch(OC.generateUrl('/apps/externalshare/admin/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(result => {
                // Validate response structure
                if (!result || typeof result !== 'object') {
                    throw new Error('Invalid response format');
                }

                if (result.status === 'success') {
                    showMessage('success', result.message || 'Settings saved successfully! External Share now available in file sharing panels.');
                } else if (result.status === 'error') {
                    showMessage('error', result.message || 'Failed to save settings. Please check your input.');
                } else {
                    showMessage('error', 'Unexpected response from server.');
                }
            })
            .catch(error => {
                let errorMessage = 'Network error occurred. Please try again.';
                if (error.message && error.message.startsWith('HTTP')) {
                    errorMessage = 'Server error: ' + error.message;
                }
                showMessage('error', errorMessage);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        });

        /**
         * Show message to user (XSS-safe)
         * @param {string} type - Message type ('success' or 'error')
         * @param {string} text - Message text to display
         */
        function showMessage(type, text) {
            // Clear previous messages
            messageDiv.textContent = '';

            // Create message element using DOM methods (XSS-safe)
            const msgElement = document.createElement('div');
            msgElement.className = 'msg ' + (type === 'success' ? 'success' : 'error');
            msgElement.textContent = text;

            messageDiv.appendChild(msgElement);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                messageDiv.textContent = '';
            }, 5000);
        }
    });
})();
