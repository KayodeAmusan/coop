<div align="center">

# OOUTH Cooperative Management System (CMS)

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-3.0-38B2AC?logo=tailwind-css&logoColor=white)](https://tailwindcss.com/)
[![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Flutter](https://img.shields.io/badge/Mobile-Flutter%20Compatible-02569B?logo=flutter&logoColor=white)](https://flutter.dev)

**A unified financial platform for managing cooperative members, loans, dividends, and payroll.**
Features a modern Tailwind CSS dashboard, RESTful APIs for mobile apps, and automated accounting engines.

[**View Live Portal**](https://www.emmaggi.com/coop_admin/)

</div>

---

## 📸 System Previews

|                              **Admin Dashboard**                               |                              **Loan Management**                              |                               **Member Ledger**                               |                        **Mobile Integration**                         |
| :----------------------------------------------------------------------------: | :---------------------------------------------------------------------------: | :---------------------------------------------------------------------------: | :-------------------------------------------------------------------: |
| <img src="screenshots/dashboard_main.png" width="200" alt="Admin Dashboard" /> | <img src="screenshots/loan_approval.png" width="200" alt="Loan Management" /> | <img src="screenshots/member_profile.png" width="200" alt="Member Profile" /> | <img src="screenshots/mobile_api.png" width="200" alt="Mobile App" /> |

---

## 🚀 System Architecture

This checkout contains the native PHP administration portal and member APIs. The platform implementation remains incomplete; see [the verified resume audit](docs/implementation/RESUME_UX_AUDIT.md) and [local development and staging guidance](docs/implementation/LOCAL_DEVELOPMENT_AND_STAGING.md).

### 1. Web Administration Portal (`/`)

Built with **Native PHP 8+** and **Tailwind CSS**.

- **Member Management:** CRUD operations for staff/members (`users.php`, `employee.php`).
- **Accounting Engine:** Double-entry ledger system (`coop_general_ledger.php`, `coop_trial_balance.php`).
- **Loan Processor:** Automated eligibility checks and amortization schedules (`loan/`).
- **Financial Reporting:** Comprehensive PDF reports and analytics (`masterReportModern.php`, `dividend/`).

### 2. API Gateway (`/api` & `/auth_api`)

RESTful endpoints serving the mobile application.

- **Authentication:** Secure login and token management (`auth_api/api/auth/`).
- **Transactional:** Endpoints for loan requests, balance checks, and savings history (`api/`).
- **Admin Operations:** Member search, loan approval, and attendance tracking (`auth_api/api/admin/`).

### 3. Member interface

`member_guarantor_evidence.php` provides the delivered evidence-specific member web interface. Member APIs are under `auth_api/api/`. The previously documented `oouth_coop_app` Flutter directory is absent from this checkout; a complete member portal and mobile acceptance remain unfinished.

---

## 🛠️ Key Modules

| Module                   | Description                                                            | Key Files                                                                 |
| :----------------------- | :--------------------------------------------------------------------- | :------------------------------------------------------------------------ |
| **💰 Finance**           | General Ledger, Trial Balance, and Journal Entries.                    | `coop_finance.php`, `coop_journal_entries.php`, `coop_general_ledger.php` |
| **💸 Loans**             | Loan application workflow, guarantor approval, and repayment tracking. | `loan-processor.php`, `loan/`, `auth_api/api/loans/`                      |
| **📊 Reporting**         | PDF generation for monthly reports and dividend sharing.               | `masterReportModern.php`, `dividend/`, `exportMemberContributions.php`    |
| **🔔 Alerts**            | Automated SMS/Email notifications for transactions.                    | `AlertSystem/`, `onesignal/`, `auth_api/api/auth/notifications.php`       |
| **📂 Import**            | Bulk data processing from Excel/CSV files.                             | `excel_import/`, `api_upload.php`                                         |
| **👥 Member Management** | Complete member lifecycle management.                                  | `users.php`, `employee.php`, `auth_api/api/members/`                      |
| **🔐 Authentication**    | Secure authentication and authorization system.                        | `auth_api/api/auth/`, `login.php`, `auth_api/admin/`                      |

---

## 🔧 Installation & Setup

### Prerequisites

- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Composer (for PHP dependencies)
- Web server (Apache/Nginx)

### Step-by-Step Setup

1.  **Clone the Repository**

    ```bash
    git clone https://github.com/meowbanky/coop_admin.git
    cd coop_admin
    ```

2.  **Database Setup**

    - Create a MySQL database named `coop_db` (or your preferred name).
    - Read [local development and staging guidance](docs/implementation/LOCAL_DEVELOPMENT_AND_STAGING.md) first. `SETUP_FULL_ACCOUNTING_SYSTEM.sql` is the root accounting schema/seed script, not a complete legacy application database bootstrap.
    - Use an authorized synthetic base schema and review incremental files under `database/migrations/`; do not bulk-run every SQL file or any `_down.sql` file.
    - Configure the root `.env`; `Connections/coop.php` reads it through `config/EnvConfig.php`.

3.  **Install PHP Dependencies**

    ```bash
    composer install
    ```

    This installs PHPMailer, dotenv, PhpSpreadsheet and Guzzle. Legacy reporting dependencies need separate verification; mPDF/TCPDF are not declared in this manifest.

4.  **Configure Environment**

    - Copy root `env.example` to root `.env` and configure only a disposable development database.
    - Follow [platform configuration](docs/implementation/PLATFORM_CONFIGURATION.md) for separate platform provisioning, encrypted integrations and private storage/scanning. Never commit secrets or use live member dispatch for tests.

5.  **Set Permissions**

    ```bash
    # Configure private document storage outside the web root.
    # Follow docs/implementation/GUARANTOR_EVIDENCE.md for runtime permissions.
    ```

6.  **Running Locally**
    ```bash
    php -S 127.0.0.1:8000 -t .
    ```
    Then navigate to `http://127.0.0.1:8000` in your development browser. This starts PHP only; working database-backed pages require the reviewed schema and synthetic accounts described in the local guide.

### Mobile App Setup (Optional)

Flutter source is not present in this checkout. Do not treat API availability as evidence of an installed or verified mobile application.

---

## 📡 API Documentation

### Authentication Endpoints

- `POST /auth_api/api/auth/login.php` - User login
- `POST /auth_api/api/auth/register.php` - User registration
- `POST /auth_api/api/auth/request_otp.php` - OTP request
- `POST /auth_api/api/auth/reset_password.php` - Password reset

### Member Endpoints

- `GET /auth_api/api/members/search.php` - Search members
- `GET /auth_api/api/profile/get_profile.php` - Get user profile
- `POST /auth_api/api/profile/submit_changes.php` - Update profile

### Loan Endpoints

- `POST /auth_api/api/loans/request.php` - Submit loan request
- `GET /auth_api/api/loans/tracking.php` - Track loan status
- `POST /auth_api/api/loans/guarantor-request.php` - Request guarantor

### Transaction Endpoints

- `GET /auth_api/api/transactions/get_periods.php` - Get transaction periods
- `GET /auth_api/api/transactions/transaction-summary.php` - Get transaction summary

For complete API documentation, see `api/API_TESTING_GUIDE.md`.

---

## 🔒 Security Features

- **Role-Based Access Control (RBAC):** Distinct views for Admins, Accountants, and Regular Users.
- **Input Sanitization:** Protection against SQL injection in core classes (`classes/class.db.php`).
- **Audit Logging:** Tracks all financial modifications (`auth_api/admin/audit_trail.php`).
- **Session Management:** Secure session handling with timeout protection.
- **Password Hashing:** Bcrypt hashing for all user passwords.
- **CORS Configuration:** Proper CORS handling for API endpoints (`auth_api/config/cors.php`).

---

## 🎨 UI/UX Highlights

- **Modern Tailwind CSS Design:** Clean, professional interface with consistent styling.
- **Responsive Layout:** Mobile-first approach that works on all devices.
- **Interactive Components:** Real-time updates, modals, progress bars, and data tables.
- **User-Friendly Navigation:** Intuitive menu structure and quick access dashboard.

---

## 📊 Database Schema

The system uses a comprehensive MySQL database with tables for:

- Members/Users (`users`, `employees`)
- Financial transactions (`transactions`, `journal_entries`)
- Loans (`loans`, `loan_applications`, `loan_repayments`)
- Accounting (`chart_of_accounts`, `general_ledger`, `trial_balance`)
- Events and Attendance (`events`, `event_attendance`)

See `database/` folder for SQL schema files.

---

## 🧪 Testing

### API Testing

Use the provided API testing guide:

```bash
# See api/API_TESTING_GUIDE.md for detailed testing instructions
```

### Manual Testing

1. Test member registration and login
2. Verify loan application workflow
3. Check financial reporting accuracy
4. Validate mobile app API integration

---

## 📱 Mobile App Integration

Member API endpoints exist, but the previously documented Flutter source directory is absent. Mobile integration, rendered browser journeys and performance on affordable phones require independent acceptance; no cross-platform completion claim is made.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

---

## 📝 License

This project is licensed under the MIT License - see the LICENSE file for details.

---

## 👤 Author

**Bankole Abiodun**

- _Developer & System Architect_
- Portfolio: [GitHub Profile](https://github.com/meowbanky)

---

## 🌐 Live Demo

- **Web Portal:** [https://www.emmaggi.com/coop_admin/](https://www.emmaggi.com/coop_admin/)
- **API Base URL:** `https://www.emmaggi.com/coop_admin/auth_api/api/`

---

## 📚 Additional Documentation

- [Accounting Engine Usage Guide](ACCOUNTING_ENGINE_USAGE_GUIDE.md)
- [API Integration Guide](API_INTEGRATION_README.md)
- [Database Structure Fixes](DATABASE_STRUCTURE_FIXES.md)
- [Loan Processor README](LOAN_PROCESSOR_README.md)
- [Quick Start API Guide](QUICK_START_API.md)
- [Event Attendance Implementation](event-attendance-implementation-guide/)

---

<div align="center">

**Built with ❤️ using PHP, MySQL, Tailwind CSS, and Flutter**

_Demonstrating Full-Stack Development Capabilities_

</div>
