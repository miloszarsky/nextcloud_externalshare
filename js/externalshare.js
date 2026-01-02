(function() {
    'use strict';

    // Prevent multiple initialization
    if (window.ExternalShareLoaded) {
        return;
    }
    window.ExternalShareLoaded = true;

    let isProcessing = false;
    let sectionExists = false;
    let isUploading = false;      // Prevents re-injection during upload
    let storedFileContext = null;

    // Wait for page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initExternalShare);
    } else {
        initExternalShare();
    }

    function initExternalShare() {
        // Watch for Vue-based sidebar
        watchForVueSidebar();

        // Watch for share button clicks
        watchShareButtons();

        // Register file action as additional option
        registerFileAction();
    }

    /**
     * Watch for Vue sidebar (#app-sidebar-vue)
     */
    function watchForVueSidebar() {
        // Try immediate injection
        attemptInjection();

        // Set up MutationObserver for Vue updates
        const observer = new MutationObserver(function(mutations) {
            // Skip if currently processing a click
            if (isProcessing) return;

            for (const mutation of mutations) {
                if (mutation.addedNodes.length || mutation.attributeName === 'class') {
                    // Let attemptInjection handle file change detection
                    attemptInjection();
                    break;
                }
            }
        });

        // Observe the entire document for Vue changes
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style']
        });

        // Periodic check (fallback for Vue reactivity)
        let checkCount = 0;
        const checkInterval = setInterval(() => {
            if (checkCount++ > 40 || sectionExists) {
                clearInterval(checkInterval);
                return;
            }
            attemptInjection();
        }, 500);
    }

    function attemptInjection() {
        // Target the Vue sidebar specifically
        const sidebar = document.querySelector('#app-sidebar-vue.app-sidebar');

        if (!sidebar || !isElementVisible(sidebar)) {
            return false;
        }

        // Find the content area
        const contentArea = sidebar.querySelector('.app-sidebar-tabs__content');

        if (!contentArea) {
            return false;
        }

        // Get current file info
        const currentFileName = getFileName();

        // Check if we already have a section
        const existingSection = contentArea.querySelector('.external-share-section');

        console.log('[ExternalShare] attemptInjection:', {
            existingSection: !!existingSection,
            sectionExists,
            isUploading,
            currentFileName
        });

        if (existingSection) {
            // Check if file changed - if so, remove old section and re-inject
            const existingFileName = existingSection.querySelector('.external-share-upload, button[data-file-name]')?.getAttribute('data-file-name');
            console.log('[ExternalShare] existingFileName:', existingFileName);

            if (existingFileName && currentFileName && existingFileName !== currentFileName) {
                console.log('[ExternalShare] File changed from', existingFileName, 'to', currentFileName);
                existingSection.remove();
                sectionExists = false;
                isUploading = false;  // Reset upload state for new file
            } else if (sectionExists || isUploading) {
                // Same file, section exists or upload in progress - don't re-inject
                console.log('[ExternalShare] Skipping injection - section exists or uploading');
                return true;
            }
        } else if (sectionExists || isUploading) {
            // No section in DOM but flags say we should have one
            console.log('[ExternalShare] No section in DOM but flags set - checking if upload in progress');
            if (isUploading) {
                // Upload in progress, don't create new section
                console.log('[ExternalShare] Upload in progress, not injecting');
                return true;
            }
            sectionExists = false;
        }

        if (!currentFileName) {
            return false;
        }

        const filePath = getFilePath(currentFileName);

        // Inject our section
        injectExternalShare(contentArea, currentFileName, filePath);
        return true;
    }

    function isElementVisible(el) {
        if (!el) return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' &&
               style.visibility !== 'hidden' &&
               style.opacity !== '0' &&
               el.offsetWidth > 0 &&
               el.offsetHeight > 0;
    }

    /**
     * Watch for share button clicks
     */
    function watchShareButtons() {
        document.addEventListener('click', function(e) {
            const target = e.target.closest('.icon-share, .action-share, [data-action="Share"], .icon-shared, .sharing-entry, [class*="share"]');

            if (target && !isProcessing) {
                isProcessing = true;
                sectionExists = false;
                isUploading = false;  // Reset on new file selection
                storeFileContextFromClick(target);

                // Remove existing section so it can be re-created with new file info
                document.querySelectorAll('.external-share-section').forEach(el => el.remove());

                // Vue sidebar takes a moment to render
                setTimeout(() => {
                    attemptInjection();
                    isProcessing = false;
                }, 300);

                setTimeout(() => attemptInjection(), 800);
                setTimeout(() => attemptInjection(), 1500);
            }
        }, true);
    }

    function storeFileContextFromClick(element) {
        const row = element.closest('tr, .file-row, [data-file], [data-id], [data-name]');

        if (row) {
            const fileName = row.getAttribute('data-file') ||
                           row.getAttribute('data-name') ||
                           row.querySelector('.filename .nametext, .filename, .name')?.textContent?.trim();

            if (fileName) {
                storedFileContext = {
                    fileName: fileName,
                    element: row
                };
            }
        }
    }

    /**
     * Inject external share section into container (XSS-safe DOM manipulation)
     */
    function injectExternalShare(container, fileName, filePath) {
        if (sectionExists) return;

        sectionExists = true;

        // Create section element
        const section = document.createElement('div');
        section.className = 'external-share-section';

        // Create header
        const header = document.createElement('div');
        header.className = 'external-share-header';

        const heading = document.createElement('h3');
        const icon = document.createElement('span');
        icon.textContent = '📤';
        icon.style.marginRight = '8px';
        heading.appendChild(icon);
        heading.appendChild(document.createTextNode('External Share'));

        const description = document.createElement('p');
        description.appendChild(document.createTextNode('Upload '));
        const strong = document.createElement('strong');
        strong.textContent = fileName;
        description.appendChild(strong);
        description.appendChild(document.createTextNode(' to external service'));

        header.appendChild(heading);
        header.appendChild(description);

        // Create upload button
        const button = document.createElement('button');
        button.className = 'external-share-upload primary';
        button.setAttribute('data-file-path', filePath);
        button.setAttribute('data-file-name', fileName);
        button.textContent = '🚀 Upload to External Service';

        // Create result container
        const resultDiv = document.createElement('div');
        resultDiv.className = 'external-share-result';

        // Assemble section
        section.appendChild(header);
        section.appendChild(button);
        section.appendChild(resultDiv);

        // Insert at the beginning of the content area
        if (container.firstChild) {
            container.insertBefore(section, container.firstChild);
        } else {
            container.appendChild(section);
        }

        // Bind event handler
        button.addEventListener('click', function(e) {
            console.log('[ExternalShare] Upload button clicked');
            e.preventDefault();
            e.stopPropagation();
            handleUpload(button);
        });

        console.log('[ExternalShare] Section injected for file:', fileName, 'path:', filePath);
    }

    function getFileName() {
        // Priority 1: Stored context from click
        if (storedFileContext?.fileName) {
            return storedFileContext.fileName;
        }

        // Priority 2: Vue sidebar header
        const sidebar = document.querySelector('#app-sidebar-vue');
        if (sidebar) {
            const mainName = sidebar.querySelector('.app-sidebar-header__mainname, .app-sidebar-header__title, h2.app-sidebar-header__name');
            if (mainName) {
                const text = mainName.textContent.trim();
                if (text && text !== 'Files' && text !== 'Nextcloud') {
                    return text;
                }
            }

            const nameContainer = sidebar.querySelector('.app-sidebar-header__name-container, .fileName, .filename');
            if (nameContainer) {
                const text = nameContainer.textContent.trim();
                if (text && text !== 'Files') {
                    return text;
                }
            }
        }

        // Priority 3: From OCA.Files
        if (typeof OCA !== 'undefined' && OCA.Files?.App?.fileList) {
            try {
                const selection = OCA.Files.App.fileList.getSelectedFiles();
                if (selection.length > 0) {
                    return selection[0].name;
                }
            } catch (e) {
                // Silent fail
            }
        }

        // Priority 4: URL filename parameter (note: fileid/openfile are IDs, not names)
        const urlParams = new URLSearchParams(window.location.search);
        const urlFileName = urlParams.get('scrollto');
        if (urlFileName) {
            return decodeURIComponent(urlFileName);
        }

        return null;
    }

    function getFilePath(fileName) {
        if (!fileName) return null;

        let currentDir = '/';

        // Try to get directory from URL
        const urlParams = new URLSearchParams(window.location.search);
        const dir = urlParams.get('dir');
        if (dir) {
            currentDir = decodeURIComponent(dir);
        } else if (typeof OCA !== 'undefined' && OCA.Files?.App?.fileList) {
            try {
                currentDir = OCA.Files.App.fileList.getCurrentDirectory();
            } catch (e) {
                // Silent fail
            }
        }

        const fullPath = currentDir === '/' ? '/' + fileName : currentDir + '/' + fileName;
        return fullPath;
    }

    function handleUpload(button) {
        const filePath = button.getAttribute('data-file-path');
        const fileName = button.getAttribute('data-file-name');

        console.log('[ExternalShare] handleUpload called', { filePath, fileName });

        if (!filePath || !fileName) {
            console.error('[ExternalShare] Missing filePath or fileName');
            showAlert('Could not determine which file to upload. Please try again.');
            return;
        }

        // Prevent re-injection during upload
        isUploading = true;

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = '⏳ Uploading...';
        button.style.opacity = '0.7';

        const formData = new FormData();
        formData.append('filePath', filePath);

        const uploadUrl = OC.generateUrl('/apps/externalshare/upload');
        console.log('[ExternalShare] Calling API:', uploadUrl, 'Token:', OC.requestToken ? 'present' : 'missing');

        fetch(uploadUrl, {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'OCS-APIREQUEST': 'true'
            },
            body: formData
        })
        .then(response => {
            console.log('[ExternalShare] Response status:', response.status);
            if (!response.ok) {
                // Try to get error details
                return response.json().then(data => {
                    console.error('[ExternalShare] Server error:', data);
                    throw new Error(data.message || `HTTP ${response.status}`);
                }).catch(() => {
                    throw new Error(`HTTP ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('[ExternalShare] Response data:', data);
            // Validate response structure
            if (!data || typeof data !== 'object') {
                throw new Error('Invalid response format');
            }

            if (data.success && data.shareLink) {
                showSuccess(button, data);
            } else {
                const errorMessage = data.message || 'Unknown error occurred';
                console.error('[ExternalShare] Upload failed:', errorMessage);
                showAlert('Upload failed: ' + errorMessage);
                isUploading = false;  // Reset on failure
                resetButton(button, originalText);
            }
        })
        .catch(error => {
            console.error('[ExternalShare] Error:', error);
            showAlert('Network error occurred. Please try again.');
            isUploading = false;  // Reset on error
            resetButton(button, originalText);
        });
    }

    function resetButton(button, originalText) {
        button.disabled = false;
        button.textContent = originalText;
        button.style.opacity = '1';
    }

    /**
     * Show success message with share link (XSS-safe DOM manipulation)
     */
    function showSuccess(button, data) {
        // isUploading stays true to prevent re-injection
        console.log('[ExternalShare] showSuccess called, isUploading =', isUploading);

        const resultDiv = button.parentElement.querySelector('.external-share-result');
        const fileName = button.getAttribute('data-file-name');

        if (!resultDiv) {
            console.error('[ExternalShare] resultDiv not found! Button parent:', button.parentElement);
            isUploading = false;
            return;
        }

        // Clear previous content
        resultDiv.textContent = '';

        // Create success container
        const successContainer = document.createElement('div');
        successContainer.className = 'external-share-success';

        // Create header
        const successHeader = document.createElement('h4');
        const checkmark = document.createElement('span');
        checkmark.textContent = '✅';
        successHeader.appendChild(checkmark);
        successHeader.appendChild(document.createTextNode(' Upload Successful!'));

        // Create link label
        const linkLabel = document.createElement('label');
        linkLabel.textContent = 'Share Link:';

        // Create input for link
        const linkInput = document.createElement('input');
        linkInput.type = 'text';
        linkInput.value = data.shareLink;
        linkInput.readOnly = true;
        linkInput.className = 'external-share-link-input';

        // Create button container for copy and email buttons
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'external-share-buttons';

        // Create copy button
        const copyButton = document.createElement('button');
        copyButton.className = 'copy-link-btn';
        copyButton.textContent = '📋 Copy to Clipboard';
        copyButton.setAttribute('data-link', data.shareLink);

        // Create email button
        const emailButton = document.createElement('button');
        emailButton.className = 'email-link-btn';
        emailButton.textContent = '📧 Send by Email';
        emailButton.setAttribute('data-link', data.shareLink);
        emailButton.setAttribute('data-file-name', fileName);

        buttonContainer.appendChild(copyButton);
        buttonContainer.appendChild(emailButton);

        // Create email form (initially hidden)
        const emailForm = createEmailForm(data.shareLink, fileName);

        // Create "Upload Another" button
        const uploadAnotherBtn = document.createElement('button');
        uploadAnotherBtn.className = 'upload-another-btn';
        uploadAnotherBtn.textContent = '🔄 Upload Another File';

        // Assemble success container
        successContainer.appendChild(successHeader);
        successContainer.appendChild(linkLabel);
        successContainer.appendChild(linkInput);
        successContainer.appendChild(buttonContainer);
        successContainer.appendChild(emailForm);
        successContainer.appendChild(uploadAnotherBtn);

        resultDiv.appendChild(successContainer);

        // Hide upload button
        button.style.display = 'none';

        // Select input on click
        linkInput.addEventListener('click', function() {
            this.select();
        });

        // Copy button handler
        copyButton.addEventListener('click', function() {
            const link = this.getAttribute('data-link');
            const btn = this;

            navigator.clipboard.writeText(link).then(() => {
                btn.textContent = '✅ Copied to Clipboard!';
                btn.style.background = '#218838';
                btn.disabled = true;
                setTimeout(() => {
                    btn.textContent = '📋 Copy to Clipboard';
                    btn.style.background = '#28a745';
                    btn.disabled = false;
                }, 2500);
            }).catch(() => {
                // Fallback: select the input
                linkInput.select();
                linkInput.setSelectionRange(0, 99999);
                showAlert('Please copy the link manually (Ctrl+C or Cmd+C)');
            });
        });

        // Email button handler - toggle form visibility
        emailButton.addEventListener('click', function() {
            emailForm.style.display = emailForm.style.display === 'none' ? 'block' : 'none';
            if (emailForm.style.display === 'block') {
                emailForm.querySelector('.email-recipient-input').focus();
            }
        });

        // Upload Another button handler - reset the section
        uploadAnotherBtn.addEventListener('click', function() {
            isUploading = false;  // Allow new upload
            resultDiv.textContent = '';
            button.style.display = '';
            button.disabled = false;
            button.textContent = '🚀 Upload to External Service';
            button.style.opacity = '1';
        });
    }

    /**
     * Create email form for sending share link (XSS-safe DOM manipulation)
     */
    function createEmailForm(shareLink, fileName) {
        const form = document.createElement('div');
        form.className = 'external-share-email-form';
        form.style.display = 'none';

        // Email input
        const emailLabel = document.createElement('label');
        emailLabel.textContent = 'Recipient Email:';
        emailLabel.className = 'email-label';

        const emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.className = 'email-recipient-input';
        emailInput.placeholder = 'recipient@example.com';
        emailInput.required = true;

        // Message textarea
        const messageLabel = document.createElement('label');
        messageLabel.textContent = 'Message (optional):';
        messageLabel.className = 'email-label';

        const messageInput = document.createElement('textarea');
        messageInput.className = 'email-message-input';
        messageInput.placeholder = 'Add a personal message...';
        messageInput.rows = 3;

        // Send button
        const sendButton = document.createElement('button');
        sendButton.className = 'send-email-btn primary';
        sendButton.textContent = '📤 Send Email';

        // Status message
        const statusDiv = document.createElement('div');
        statusDiv.className = 'email-status';

        form.appendChild(emailLabel);
        form.appendChild(emailInput);
        form.appendChild(messageLabel);
        form.appendChild(messageInput);
        form.appendChild(sendButton);
        form.appendChild(statusDiv);

        // Send button handler
        sendButton.addEventListener('click', function() {
            const email = emailInput.value.trim();
            const message = messageInput.value.trim();

            if (!email) {
                showEmailStatus(statusDiv, 'Please enter an email address.', false);
                emailInput.focus();
                return;
            }

            // Basic email validation
            if (!isValidEmail(email)) {
                showEmailStatus(statusDiv, 'Please enter a valid email address.', false);
                emailInput.focus();
                return;
            }

            // Disable button and show loading
            sendButton.disabled = true;
            sendButton.textContent = '⏳ Sending...';

            const formData = new FormData();
            formData.append('email', email);
            formData.append('shareLink', shareLink);
            formData.append('fileName', fileName);
            formData.append('message', message);

            fetch(OC.generateUrl('/apps/externalshare/sendmail'), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showEmailStatus(statusDiv, data.message || 'Email sent successfully!', true);
                    emailInput.value = '';
                    messageInput.value = '';
                    // Hide form after success
                    setTimeout(() => {
                        form.style.display = 'none';
                        statusDiv.textContent = '';
                    }, 3000);
                } else {
                    showEmailStatus(statusDiv, data.message || 'Failed to send email.', false);
                }
                sendButton.disabled = false;
                sendButton.textContent = '📤 Send Email';
            })
            .catch(error => {
                showEmailStatus(statusDiv, 'Network error. Please try again.', false);
                sendButton.disabled = false;
                sendButton.textContent = '📤 Send Email';
            });
        });

        return form;
    }

    /**
     * Show email status message
     */
    function showEmailStatus(container, message, success) {
        container.textContent = message;
        container.className = 'email-status ' + (success ? 'success' : 'error');
    }

    /**
     * Basic email validation
     */
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function registerFileAction() {
        if (typeof OCA !== 'undefined' && OCA.Files?.fileActions) {
            OCA.Files.fileActions.registerAction({
                name: 'ExternalShare',
                displayName: 'External Share',
                mime: 'all',
                permissions: OC.PERMISSION_READ,
                iconClass: 'icon-external',
                order: -100,
                actionHandler: function(fileName, context) {
                    const filePath = (context.fileList?.getCurrentDirectory() || '/') + '/' + fileName;
                    showDialog(fileName, filePath);
                }
            });
        }
    }

    /**
     * Show dialog for file action (XSS-safe DOM manipulation)
     */
    function showDialog(fileName, filePath) {
        const dialog = document.createElement('div');
        dialog.className = 'external-share-dialog';

        const dialogContent = document.createElement('div');
        dialogContent.className = 'external-share-dialog-content';

        const heading = document.createElement('h3');
        heading.textContent = '📤 External Share';

        const message = document.createElement('p');
        message.appendChild(document.createTextNode('Upload '));
        const fileNameStrong = document.createElement('strong');
        fileNameStrong.textContent = fileName;
        message.appendChild(fileNameStrong);
        message.appendChild(document.createTextNode(' to external service?'));

        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'external-share-dialog-buttons';

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'cancel-btn';
        cancelBtn.textContent = 'Cancel';

        const uploadBtn = document.createElement('button');
        uploadBtn.className = 'upload-btn primary';
        uploadBtn.textContent = '🚀 Upload';

        const resultContainer = document.createElement('div');
        resultContainer.className = 'dialog-result';

        buttonContainer.appendChild(cancelBtn);
        buttonContainer.appendChild(uploadBtn);

        dialogContent.appendChild(heading);
        dialogContent.appendChild(message);
        dialogContent.appendChild(buttonContainer);
        dialogContent.appendChild(resultContainer);

        dialog.appendChild(dialogContent);
        document.body.appendChild(dialog);

        // Event handlers
        cancelBtn.addEventListener('click', () => dialog.remove());

        uploadBtn.addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = '⏳ Uploading...';

            const formData = new FormData();
            formData.append('filePath', filePath);

            fetch(OC.generateUrl('/apps/externalshare/upload'), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data || typeof data !== 'object') {
                    throw new Error('Invalid response');
                }

                if (data.success && data.shareLink) {
                    showDialogSuccess(resultContainer, data.shareLink, fileName, btn, cancelBtn);
                } else {
                    showAlert('Upload failed: ' + (data.message || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = '🚀 Upload';
                }
            })
            .catch(error => {
                showAlert('Network error: ' + error.message);
                btn.disabled = false;
                btn.textContent = '🚀 Upload';
            });
        });

        // Close on background click
        dialog.addEventListener('click', function(e) {
            if (e.target === dialog) {
                dialog.remove();
            }
        });
    }

    function showDialogSuccess(container, shareLink, fileName, uploadBtn, cancelBtn) {
        container.textContent = '';

        const successDiv = document.createElement('div');
        successDiv.className = 'dialog-success';

        const heading = document.createElement('h4');
        heading.textContent = '✅ Success!';

        const input = document.createElement('input');
        input.type = 'text';
        input.value = shareLink;
        input.readOnly = true;
        input.className = 'dialog-link-input';

        // Button container
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'dialog-button-container';

        const copyBtn = document.createElement('button');
        copyBtn.textContent = '📋 Copy Link';
        copyBtn.className = 'dialog-copy-btn';

        const emailBtn = document.createElement('button');
        emailBtn.textContent = '📧 Send Email';
        emailBtn.className = 'dialog-email-btn';

        buttonContainer.appendChild(copyBtn);
        buttonContainer.appendChild(emailBtn);

        // Email form (initially hidden)
        const emailForm = createEmailForm(shareLink, fileName);

        successDiv.appendChild(heading);
        successDiv.appendChild(input);
        successDiv.appendChild(buttonContainer);
        successDiv.appendChild(emailForm);
        container.appendChild(successDiv);

        uploadBtn.style.display = 'none';
        cancelBtn.textContent = 'Close';

        input.addEventListener('click', function() {
            this.select();
        });

        copyBtn.addEventListener('click', function() {
            navigator.clipboard.writeText(shareLink).then(() => {
                this.textContent = '✅ Copied!';
                this.disabled = true;
                setTimeout(() => {
                    this.textContent = '📋 Copy Link';
                    this.disabled = false;
                }, 2000);
            }).catch(() => {
                input.select();
                showAlert('Please copy manually');
            });
        });

        emailBtn.addEventListener('click', function() {
            emailForm.style.display = emailForm.style.display === 'none' ? 'block' : 'none';
            if (emailForm.style.display === 'block') {
                emailForm.querySelector('.email-recipient-input').focus();
            }
        });
    }

    /**
     * Show alert dialog
     */
    function showAlert(message) {
        if (typeof OC !== 'undefined' && OC.dialogs && OC.dialogs.alert) {
            OC.dialogs.alert(message, 'External Share');
        } else {
            alert(message);
        }
    }

    // Debug helpers (only expose minimal interface)
    window.ExternalShare = {
        version: '1.0.0',
        reset: () => {
            sectionExists = false;
            isProcessing = false;
            isUploading = false;
            storedFileContext = null;
            document.querySelectorAll('.external-share-section').forEach(el => el.remove());
            console.log('[ExternalShare] Reset complete');
        }
    };

})();
