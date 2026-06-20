# Attendance & Wage Module

## Kiosk Flow (No Login Required)

```
Login Page → "📋 Get Attendance"
  → Step 1: Search employee (instant, no GPS yet)
  → Step 2: GPS verifies location, shows result
  → Step 3: Take photo (required), Clock In/Out
  → Step 4: Success → View My Attendance
```

### GPS Location Rules
| Location | Onsite Toggle | Result |
|----------|--------------|--------|
| Inside geo-fence | Any | ✅ Clock in with office name |
| Outside geo-fence | ON | 🏗️ Clock in with onsite place name |
| Outside geo-fence | OFF | ❌ Advised to go to office |

## API Endpoints

### Public (no auth)
| Method | Route | Handler |
|--------|-------|---------|
| GET/POST | `/api/v1/kiosk/search` | Search employees by name |
| POST | `/api/v1/kiosk/clock` | Clock in/out with photo + geo-fence |
| GET | `/api/v1/kiosk/reverse-geocode` | Coordinates → place name (Google Maps or OSM) |
| POST | `/api/v1/kiosk/verify-location` | Check if within office geo-fence |
| GET | `/api/v1/kiosk/status` | Check clock-in status for employee |
| GET | `/api/v1/kiosk/my-records` | Recent attendance records |

### Admin (auth required)
| Method | Route | Handler |
|--------|-------|---------|
| GET | `/admin/attendance` | Attendance records with hours |
| GET | `/admin/attendance?employee_id=X&export=csv` | CSV export |
| GET | `/api/v1/attendance/photo/{file}` | View attendance photo |
| GET | `/api/v1/wage/logo/{file}` | View uploaded logo |

## File Storage
- **Attendance photos**: `storage/uploads/attendance/`
- **Logo**: `storage/uploads/logos/`
- Uses `STORAGE_PATH` constant (portable across servers)

## Payroll Integration
Clock-in triggers `kioskAutoRecompute()` → auto-updates salary for current active payroll period. Admin then approves/pays via Wage → Computations.

## Entity View System
Employee list uses `{ikb_entity_list}` with 3-layer architecture:
| Layer | File | Purpose |
|-------|------|---------|
| Compact defaults | `kernel/EntityContext/EntityViewResolver.php` | Fallback field list |
| Registered view | `helpers/entity-views.php` | Custom fields + renderers |
| SQL handler | `helpers.php` (`aw_cap_entity_list_employee_profile_1`) | Actual data query |

**Critical rule**: SQL column aliases MUST match view contract field names. No auto-mapping.
See `/memories/repo/entity-view-system-proven-2026-06-20.md` for full details.

## Key Files
```
handlers/
  130-api-kiosk.php     — Kiosk clock-in/out, search, geo-fence, reverse-geocode
  10-pages-attendance.php — Admin records view + CSV export
  20-api-attendance.php  — Photo/logo serving
  00-bootstrap.php       — Auth guard, session expiry redirect
  40-api-employees.php   — Employee CRUD, onsite toggle fix

templates/
  attendance/kiosk.disyl — Alpine.js kiosk widget (full flow)
  attendance/records.disyl — Admin records table with hours, photos, CSV export
  auth/login.disyl       — Login page with logo

helpers/
  helpers.php            — Entity list handlers, geo-fence (Haversine)
  entity-views.php       — Employee table view config
```
