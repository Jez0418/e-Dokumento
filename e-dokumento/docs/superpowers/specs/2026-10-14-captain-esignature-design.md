# Captain e-signature on certificates — design

Date: 2026-10-14

## Goal

Certificates for document types that need the Punong Barangay's approval
carry the captain's signature image automatically, so no one has to
wet-sign each one. Only the captain can provide or change that signature.

## Scope

- In: an e-signature **image** (option 1), uploaded once by the captain,
  stamped on certificates of types with `requires_captain_approval = true`,
  and acknowledged on the public verify page.
- Out: cryptographic/PKI PDF signing, draw-on-screen pad, per-document
  frozen copies of the signature.

## Findings that shape the design

- `profiles` and `barangay_officials` are not linked. The signatory is the
  active `punong_barangay` official; "the captain" as a user is any profile
  with role `captain`.
- `barangay_officials.signature_path` already exists but is unused.
- Admins can update any official row (`03_policies.sql`), so "captain only"
  must be enforced in the database.

## Design

### 1. Storage
New private bucket `signatures` (PNG/JPG, 1 MB max, no public access).
`barangay_officials.signature_path` holds the object path. The image is
served through the existing `/files` proxy pattern so it cannot be
hot-linked.

### 2. Authorization (database-enforced)
- RPC `set_captain_signature(p_path text)`, `security definer`, requires
  `has_role('captain')`, writes to the active `punong_barangay` row. Passing
  null removes the signature.
- Trigger on `barangay_officials` (insert/update): rejects setting or
  changing `signature_path` unless `has_role('captain')`. Closes the admin
  loophole while leaving other official fields admin-editable.
- Storage RLS: only the captain may write to `signatures`; reads happen
  only through the certificate flow.
- Changes are covered by the existing `audit_officials` trigger.

### 3. Captain UI
A "My signature" card on `/profile`, visible only to captains: upload,
preview, replace, remove. Validation reuses `Upload` magic-byte sniffing,
restricted to PNG/JPG with a 1 MB limit.

### 4. Stamping
`pages/documents/print.php` renders the signature image above the
signatory name only when **all** hold:
- the document type has `requires_captain_approval`;
- the document is not revoked;
- the captain has a signature on file.

The footnote then reads "Electronically signed by the Punong Barangay.
Valid only with the barangay dry seal." Types without captain approval
keep the current wet-signature layout and note.

### 5. Verify page
`pages/public/verify.php` adds "Electronically signed by <name>" for
signed document types. The image is never exposed publicly.

### 6. Decisions
- Upload only; a draw pad can come later.
- The signature follows the official, not the document: replacing it
  changes reprints of older certificates.
- Residents see the signature on their own certificate.

## Testing
- SQL tests: captain can set/remove; admin, secretary, treasurer and
  resident are rejected via the RPC and via direct `update`/`insert` of
  `signature_path`; other official fields remain admin-editable.
- PHP unit test for the stamping condition (type flag, revoked, missing
  signature).
