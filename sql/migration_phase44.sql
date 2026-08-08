-- AiServe Shared WhatsApp Inbox - Phase 44 migration
-- WebAuthn credentials (biometric / passkey unlock).
--
-- One row per (user, device). Device stores the private key in its
-- secure enclave (Face ID / Touch ID / Android biometric); we store
-- only the public key + credential id.
--
-- Uses browser's PublicKeyCredential.getPublicKey() to get the key in
-- SPKI DER format directly — avoids server-side CBOR + COSE key
-- parsing that a full attestation-verifying WebAuthn implementation
-- would need. Signature verification is straight openssl_verify().
--
-- sign_count tracks the authenticator's monotonic counter and blocks
-- signature replay: if a new assertion presents a count <= stored,
-- someone cloned the credential.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_webauthn_credentials (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  credential_id_b64 VARCHAR(255) NOT NULL,      -- base64url of the raw credential id
  public_key_pem    TEXT         NOT NULL,      -- SPKI PEM
  sign_count        INT UNSIGNED NOT NULL DEFAULT 0,
  device_name       VARCHAR(120) DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at      DATETIME     DEFAULT NULL,
  UNIQUE KEY uk_uwc_credid (credential_id_b64),
  KEY idx_uwc_user (user_id),
  CONSTRAINT fk_uwc_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
