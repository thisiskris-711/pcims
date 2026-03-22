# PCIMS - Personal Collection Inventory Management System

A comprehensive inventory management system designed for Personal Collection Direct Selling, Inc. This system provides stock monitoring, notification alerts, and role-based access control for efficient inventory management.

## Features

### Core Features
- **Product Management**: Add, edit, and manage products with categories and suppliers
- **Inventory Tracking**: Real-time stock level monitoring and management
- **Stock Movements**: Complete tracking of all stock in/out movements
- **Stock Adjustments**: Easy stock adjustment with audit trail
- **Purchase Orders**: Manage purchase orders and supplier relationships
- **Sales Orders**: Track sales and order fulfillment

### Advanced Features
- **Role-Based Access Control**: Admin, Manager, Staff, and Viewer roles
- **Notification System**: Automatic low stock alerts and system notifications
- **Real-time Updates**: Live stock level updates across the system
- **Reporting & Analytics**: Comprehensive reports and data export
- **Search & Filtering**: Advanced search and filtering capabilities
- **Responsive Design**: Mobile-friendly interface using Bootstrap

### Security Features
- **Secure Authentication**: Password hashing and session management
- **CSRF Protection**: Cross-site request forgery protection
- **Activity Logging**: Complete audit trail of all user activities
- **Input Validation**: Comprehensive input sanitization and validation

## Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5
- **Backend**: PHP 8.x
- **Database**: MySQL 8.x
- **Icons**: Font Awesome 6
- **Charts**: Chart.js

## Installation Requirements

### Server Requirements
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache or Nginx web server
- PHP Extensions:
  - PDO MySQL
  - JSON
  - MBString
  - GD (for image processing)

### Web Server Configuration
- Enable mod_rewrite (for Apache)
- Set document root to the project directory
- Configure PHP error logging

## Installation Guide

### 1. Database Setup

1. Create a new MySQL database:
```sql
CREATE DATABASE pcims_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import the database schema:
```bash
mysql -u username -p pcims_db < database.sql
mysql -u username -p pcims_db < database_additional.sql
```

3. Update database credentials in `config/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pcims_db');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
```

### 2. File Setup

1. Extract the project files to your web server directory
2. Set proper file permissions:
```bash
chmod 755 -R /path/to/pcims
chmod 777 uploads/
chmod 777 logs/
```

3. Create necessary directories:
```bash
mkdir uploads/
mkdir uploads/products/
mkdir uploads/documents/
mkdir logs/
```

### 3. Configuration

1. Update the application configuration in `config/config.php`:
   - Database credentials
   - Application URL
   - Email settings (for notifications)
   - File upload settings

2. Configure your web server:
   - Point document root to the project directory
   - Enable URL rewriting
   - Set up SSL (recommended for production)

### 4. Access the System

1. Open your browser and navigate to:
   ```
   http://localhost/pcims
   ```

2. Default login credentials:
   - Username: `admin`
   - Password: `admin123`

3. **Important**: Change the default admin password immediately after first login

## User Roles and Permissions

### Admin
- Full system access
- User management
- System configuration
- All inventory operations
- Reports and analytics

### Manager
- Product and inventory management
- Purchase and sales orders
- Stock adjustments
- View reports
- Manage categories and suppliers

### Staff
- View and update inventory
- Process stock movements
- Create purchase and sales orders
- View assigned notifications

### Viewer
- Read-only access to inventory
- View reports
- View notifications

## System Navigation

### Main Menu
- **Dashboard**: Overview of system status and quick actions
- **Products**: Product catalog management
- **Inventory**: Stock level monitoring and adjustments
- **Stock Movements**: Complete movement history
- **Purchase Orders**: Supplier order management
- **Sales Orders**: Customer order tracking
- **Suppliers**: Supplier information management
- **Categories**: Product categorization
- **Users**: User account management (Admin only)
- **Reports**: System reports and analytics
- **Notifications**: System notifications and alerts

### Quick Actions
- Add new products
- Stock adjustments
- Create purchase/sales orders
- Export data

## API Endpoints

The system includes RESTful API endpoints for real-time updates:

- `/api/stock_levels.php` - Get current stock levels
- `/api/notifications.php` - Get notification counts
- `/api/upload.php` - Handle file uploads

## Security Considerations

### Production Deployment
1. **Change Default Credentials**: Always change default admin password
2. **Database Security**: Use strong database passwords
3. **File Permissions**: Restrict file access permissions
4. **HTTPS**: Enable SSL/TLS encryption
5. **Regular Updates**: Keep PHP and MySQL updated
6. **Backup Strategy**: Implement regular database backups

### Recommended Security Measures
- Enable PHP error logging only in development
- Restrict database access to localhost
- Use prepared statements (already implemented)
- Implement rate limiting for API endpoints
- Regular security audits

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check database credentials in config.php
   - Verify MySQL server is running
   - Ensure database exists and permissions are correct

2. **File Upload Issues**
   - Check upload directory permissions
   - Verify PHP upload limits in php.ini
   - Ensure disk space is available

3. **Session Issues**
   - Check session save path permissions
   - Verify session configuration in php.ini
   - Clear browser cookies and cache

4. **Performance Issues**
   - Optimize MySQL queries
   - Add database indexes
   - Enable PHP OPcache
   - Consider caching for frequent queries

### Error Logging
- Check PHP error logs at `logs/error.log`
- Enable MySQL slow query log for database optimization
- Monitor system resources

## Support and Maintenance

### Regular Maintenance Tasks
1. **Database Backups**: Daily automated backups
2. **Log Rotation**: Weekly log file cleanup
3. **System Updates**: Monthly security updates
4. **Performance Monitoring**: Regular performance checks

### Feature Enhancements
The system is designed to be extensible. Common enhancements include:
- Barcode/QR code support
- Multi-warehouse management
- Advanced reporting dashboards
- Mobile app integration
- Third-party system integrations

## License

This system is developed for Personal Collection Direct Selling, Inc. All rights reserved.

## Contact

For technical support or questions about the system:
- System Administrator: admin@pcollection.com
- Development Team: dev@pcollection.com

---

**Version**: 1.0.0  
**Last Updated**: March 2026  
**Compatible**: PHP 8.0+, MySQL 8.0+


