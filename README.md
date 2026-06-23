## Development

### Architecture

The plugin is built entirely on top of WordPress and WooCommerce APIs and does not modify any WooCommerce core files.

Main components:

- WooCommerce administration pages
- Bulk order actions
- AJAX request handlers
- Activity logging system
- Order preview engine
- Operation locking mechanism

### Data Storage

The plugin stores its configuration and operational data using standard WordPress options and WooCommerce order metadata when required.

No WooCommerce database tables are modified.

### Security

Several security measures are implemented:

- Nonce verification for all AJAX requests
- Capability checks before executing operations
- Input sanitization and validation
- Safe database interactions using WordPress APIs

### WooCommerce Compatibility

The plugin is designed to work with:

- Traditional WooCommerce order storage
- High Performance Order Storage (HPOS)

### Extending the Plugin

Developers can extend the plugin by:

- Adding new bulk actions
- Creating custom order filters
- Integrating additional order statuses
- Extending activity logs
- Adding custom validation rules

### Coding Standards

The project follows:

- WordPress Coding Standards
- WooCommerce Development Guidelines
- Secure WordPress Plugin Development Practices

---

# توسعه افزونه

## معماری

این افزونه به طور کامل بر پایه APIهای وردپرس و ووکامرس توسعه داده شده و هیچ تغییری در فایل‌های اصلی ووکامرس ایجاد نمی‌کند.

بخش‌های اصلی افزونه شامل موارد زیر است:

- صفحات مدیریتی ووکامرس
- عملیات گروهی روی سفارشات
- پردازش درخواست‌های AJAX
- سیستم ثبت تاریخچه فعالیت‌ها
- سیستم پیش نمایش سفارشات
- مکانیزم جلوگیری از تداخل عملیات

## ذخیره سازی اطلاعات

اطلاعات مورد نیاز افزونه از طریق مکانیزم‌های استاندارد وردپرس و متای سفارشات ووکامرس ذخیره می‌شود.

این افزونه هیچ تغییری در ساختار جداول اصلی ووکامرس ایجاد نمی‌کند.

## امنیت

برای افزایش امنیت افزونه موارد زیر پیاده سازی شده است:

- بررسی Nonce در تمامی درخواست‌های AJAX
- بررسی سطح دسترسی کاربران
- اعتبارسنجی و پاکسازی داده‌های ورودی
- استفاده از توابع استاندارد وردپرس برای ارتباط با پایگاه داده

## سازگاری با ووکامرس

افزونه با هر دو ساختار ذخیره سازی سفارشات ووکامرس سازگار است:

- Legacy Order Storage
- HPOS (High Performance Order Storage)

## توسعه قابلیت‌ها

توسعه دهندگان می‌توانند امکانات زیر را به افزونه اضافه کنند:

- عملیات گروهی جدید
- فیلترهای سفارشی سفارشات
- وضعیت‌های اختصاصی سفارش
- گزارش‌ها و لاگ‌های بیشتر
- قوانین اعتبارسنجی سفارشی

## استانداردهای کدنویسی

این پروژه بر اساس استانداردهای زیر توسعه داده شده است:

- WordPress Coding Standards
- WooCommerce Development Guidelines
- Secure WordPress Plugin Development Practices
