/**
 * Create PWA Icons Script
 * This script helps create properly sized PWA icons from your existing logo
 */

const fs = require('fs');
const path = require('path');

// Create placeholder icons with proper sizes
const createPlaceholderIcons = () => {
    const iconsDir = path.join(__dirname, 'images', 'icons');
    const screenshotsDir = path.join(__dirname, 'images', 'screenshots');
    
    // Create directories if they don't exist
    if (!fs.existsSync(iconsDir)) {
        fs.mkdirSync(iconsDir, { recursive: true });
    }
    
    if (!fs.existsSync(screenshotsDir)) {
        fs.mkdirSync(screenshotsDir, { recursive: true });
    }
    
    console.log('PWA Icon Creation Instructions:');
    console.log('================================');
    console.log('');
    console.log('To complete your PWA setup, you need to create the following images:');
    console.log('');
    console.log('1. ICONS (copy and resize your existing pc-logo-2.png):');
    console.log('   - 72x72.png  - Small icon');
    console.log('   - 96x96.png  - Medium icon');
    console.log('   - 128x128.png - Large icon');
    console.log('   - 144x144.png - Extra large icon');
    console.log('   - 152x152.png - iPad icon');
    console.log('   - 192x192.png - Standard PWA icon');
    console.log('   - 384x384.png - High-res icon');
    console.log('   - 512x512.png - App store icon');
    console.log('');
    console.log('2. SCREENSHOTS:');
    console.log('   - desktop-wide.png (1280x720) - Desktop dashboard view');
    console.log('   - mobile-narrow.png (375x667) - Mobile view');
    console.log('');
    console.log('3. BADGE ICON:');
    console.log('   - badge-72x72.png (72x72) - Notification badge icon');
    console.log('');
    console.log('You can use online tools like:');
    console.log('- https://www.favicon-generator.org/');
    console.log('- https://realfavicongenerator.net/');
    console.log('- https://pwa-asset-generator.github.io/');
    console.log('');
    console.log('Or use image editing software like GIMP, Photoshop, or Canva.');
    console.log('');
    console.log('Place the icons in: /images/icons/');
    console.log('Place screenshots in: /images/screenshots/');
    console.log('');
};

// Update manifest.json with proper icons
const updateManifest = () => {
    const manifest = {
        "name": "PCIMS - Personal Collection Inventory Management System",
        "short_name": "PCIMS",
        "description": "A comprehensive inventory management system for personal collections",
        "start_url": "/pcims/",
        "scope": "/pcims/",
        "display": "standalone",
        "background_color": "#f8f9fc",
        "theme_color": "#007bff",
        "orientation": "any",
        "icons": [
            {
                "src": "/pcims/images/icons/icon-72x72.png",
                "sizes": "72x72",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-96x96.png",
                "sizes": "96x96",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-128x128.png",
                "sizes": "128x128",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-144x144.png",
                "sizes": "144x144",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-152x152.png",
                "sizes": "152x152",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-192x192.png",
                "sizes": "192x192",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-384x384.png",
                "sizes": "384x384",
                "type": "image/png",
                "purpose": "any"
            },
            {
                "src": "/pcims/images/icons/icon-512x512.png",
                "sizes": "512x512",
                "type": "image/png",
                "purpose": "any"
            }
        ],
        "screenshots": [
            {
                "src": "/pcims/images/screenshots/desktop-wide.png",
                "sizes": "1280x720",
                "type": "image/png",
                "form_factor": "wide",
                "label": "PCIMS Desktop Dashboard"
            },
            {
                "src": "/pcims/images/screenshots/mobile-narrow.png", 
                "sizes": "375x667",
                "type": "image/png",
                "form_factor": "narrow",
                "label": "PCIMS Mobile View"
            }
        ],
        "categories": ["business", "productivity", "utilities"],
        "lang": "en",
        "dir": "ltr",
        "prefer_related_applications": false
    };
    
    fs.writeFileSync(path.join(__dirname, 'manifest.json'), JSON.stringify(manifest, null, 2));
    console.log('Manifest updated with proper icon structure.');
};

// Run the script
createPlaceholderIcons();
updateManifest();
