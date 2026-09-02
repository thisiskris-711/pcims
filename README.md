# PCIMS (Inventory Management System)

PCIMS is a PHP-based Inventory Management System designed to help manage inventory, warehouse operations, and sales. 

## Prerequisites

- PHP 7.4 or 8.x
- Composer (for dependency management)
- MySQL / MariaDB
- Web Server (Apache/Nginx/XAMPP)

## Deployment & Installation Instructions

Follow these steps to deploy the application on a local server (like XAMPP) or a production environment:

1. **Clone or Extract the Project**
   Place the project files into your web server's document root (e.g., `C:\xampp\htdocs\pcims`).

2. **Install Dependencies**
   Navigate to the project root directory in your terminal and run Composer to install the required PHP packages (like PHPMailer).
   ```bash
   composer install
   ```

3. **Environment Configuration**
   Copy the example environment file and rename it to `.env`:
   ```bash
   cp .env.example .env
   ```
   Open the `.env` file and update the settings, particularly:
   - `DB_NAME`, `DB_USER`, and `DB_PASS` (Your database credentials)
   - `SMTP_*` settings (If you need email functionality to work)

4. **Database Setup**
   - Create a new MySQL database (e.g., `inventory_ms` as per `.env.example`).
   - Import your initial database schema. (If there is a `.sql` file provided, import it now).
   - Run the setup script to initialize the audit logs and roles. From the project root, run:
     ```bash
     php setup_db.php
     ```

5. **Permissions**
   Ensure that the web server has appropriate read/write permissions for directories that might require file uploads or logging, such as the `uploads/` or `logs/` directories.

6. **Accessing the Application**
   The application is configured to redirect to the `public/` folder. Access the application in your web browser:
   ```text
   http://localhost/pcims/
   ```
   *(Adjust the URL according to your local setup or domain name).*

## Project Structure

- `public/` - Web root directory containing publicly accessible assets (CSS, JS) and routing logic.
- `src/` - Contains the core application source code.
- `config/` - Configuration files.
- `views/` - UI templates and view files.
- `database/` - Database related scripts or schemas.
- `vendor/` - Composer dependencies.
