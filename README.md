# Tommee Tippee Natural Start Healthcare Centres Registration

WordPress plugin for the Tommee Tippee Natural Start healthcare centres registration form. It follows the HC DORB registration plugin, with a new title, organization types, and a required distribution confirmation.

**Plugin file:** `hc-tt-registration-plugin.php`  
**Version:** `1.0.0`  
**Text domain:** `hc-tt-registration`  
**Database table:** `{prefix}hc_tt_registration_submissions` (e.g. `wp_hc_tt_registration_submissions`)

---

## Installation

1. The folder `hc-tt-registration-plugin` is already in `wp-content/plugins/`.
2. Activate **Tommee Tippee Natural Start Healthcare Centres Registration** under **Plugins**.
3. Add `[hc_tt_registration]` to a page or post.

---

## Shortcode

```text
[hc_tt_registration]
```

### Attributes

| Attribute | Default | Purpose |
|-----------|---------|---------|
| `thank_you_url` | Current page | Custom URL after successful submit |
| `contact_email` | `info@babybrandsgiftclub.com` | Shown on thank-you screen |
| `notification_email` | *(empty)* | If set, submission summary is emailed here |

### Example

```text
[hc_tt_registration contact_email="support@example.com" notification_email="admin@example.com"]
```

---

## Form differences vs HC DORB Registration

- **Title:** Tommee Tippee Natural Start Healthcare Centres Registration
- **Organization Type** replaces Category. Options: Community Centre, Doctor's Office, Doula Services, Family Health Team, Hospital, Medical Centre, Midwifery Practice, OBGYN Practice, Pediatric Practice, Pregnancy Centre, Public Health Unit, Trade Show, Other
- **Added** required confirmation: the centre will distribute Tommee Tippee Feeding Bottle to expecting parents only and encourage QR registration
- **Kept** from DORB: Yes / No for receiving samples of Diapers, Sudocrem, and wipes

---

## Admin

- **TT Healthcare Registrations** menu — list, search, export CSV, view, edit, delete submissions

---

## Database columns

`healthcare_id`, `organization_name`, contact fields, address fields, `category` (organization type), `category_other`, `patients_type`, `weekly_expecting_parents`, `receive_patient_samples` (`Yes` / `No`), **`confirmed_distribution`** (`1` / `0`), `comments`, `created_at`

Table is created via `dbDelta()` on `init`.

---

## Files

| File | Purpose |
|------|---------|
| `hc-tt-registration-plugin.php` | Bootstrap, form, admin, DB |
| `includes/class-hctt-submissions-list-table.php` | Admin list table |
| `assets/css/style.css` | Form styles |
| `assets/images/step1.webp` | Header image |

---

## Hooks

- **`hctt_contact_email`** — Filter contact email on thank-you / validation
- **`hctt_send_notification_email`** — `(bool $send, int $insert_id)` — Return `false` to skip notification email
- **`hctt_created_at_timezone`** — Filter timezone for `created_at` (default `America/New_York`)

Form posts to `admin-post.php` with `action=hctt_register`.
