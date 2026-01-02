<?php
script('externalshare', 'admin-settings');
style('externalshare', 'admin-settings');
?>
<div id="externalshare-admin" class="section">
    <h2><?php p($l->t('External Share')); ?></h2>
    <p class="settings-hint"><?php p($l->t('Add external upload option to file sharing panels. Users will see "External Share" section when sharing files.')); ?></p>
    
    <form id="externalshare-form">
        <div class="form-group">
            <label for="upload_url"><?php p($l->t('Upload Service URL')); ?></label>
            <input type="url"
                   id="upload_url"
                   name="upload_url"
                   value="<?php p($_['upload_url']); ?>"
                   placeholder="https://transfer.sh"
                   required />
            <p class="settings-hint">
                <?php p($l->t('URL where files will be uploaded (e.g., transfer.sh, 0x0.st)')); ?>
            </p>
        </div>

        <div class="form-group">
            <label for="http_method"><?php p($l->t('HTTP Method')); ?></label>
            <select id="http_method" name="http_method">
                <option value="POST" <?php if ($_['http_method'] === 'POST') echo 'selected'; ?>>POST (multipart/form-data)</option>
                <option value="PUT" <?php if ($_['http_method'] === 'PUT') echo 'selected'; ?>>PUT (raw file upload)</option>
            </select>
            <p class="settings-hint">
                <?php p($l->t('POST is more widely supported. Use PUT for WebDAV-style services or transfer.sh')); ?>
            </p>
        </div>

        <div class="form-group">
            <label for="auth_token"><?php p($l->t('Authentication Token (Optional)')); ?></label>
            <input type="password" 
                   id="auth_token" 
                   name="auth_token" 
                   value="<?php p($_['auth_token']); ?>" 
                   placeholder="Bearer token or API key" />
            <p class="settings-hint">
                <?php p($l->t('Will be sent as Authorization: Bearer [token] header')); ?>
            </p>
        </div>

        <div class="form-group">
            <label for="custom_headers"><?php p($l->t('Custom Headers (Optional)')); ?></label>
            <textarea id="custom_headers" 
                      name="custom_headers" 
                      rows="3" 
                      placeholder="Max-Days: 7&#10;Max-Downloads: 100"><?php p($_['custom_headers']); ?></textarea>
            <p class="settings-hint">
                <?php p($l->t('One header per line, format: Header-Name: value')); ?>
            </p>
        </div>

        <button type="submit" class="primary"><?php p($l->t('Save Settings')); ?></button>
        
        <div class="usage-info">
            <h3><?php p($l->t('How to use')); ?></h3>
            <ol>
                <li><?php p($l->t('Select any file in Files app')); ?></li>
                <li><?php p($l->t('Click the share icon to open sharing panel')); ?></li>
                <li><?php p($l->t('Find "External Share" section in the sharing panel')); ?></li>
                <li><?php p($l->t('Click "Upload" to get a shareable link')); ?></li>
            </ol>
        </div>
    </form>
    
    <div id="externalshare-message"></div>
</div>
