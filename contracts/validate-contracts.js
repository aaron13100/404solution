#!/usr/bin/env node

// Pre-commit gate for outbound contracts.
// Validates contracts.json wiring: schemas exist, fixtures pass/fail correctly,
// test files exist and reference their contract IDs.
//
// Usage: node validate-contracts.js [--contracts-dir <path>] [--vendor-dir <path>]
//                                   [--server-contracts-dir <path>]
// Defaults: --contracts-dir ./contracts --vendor-dir ./vendor-contracts
//           --server-contracts-dir ../404-solution-server/contracts
//                                  (env: ABJ404_SERVER_CONTRACTS_DIR)
//
// Exit 0: all checks pass (or no contracts directory found)
// Exit 1: validation failure

const path = require("path");
const {
  fileExists,
  pathExists,
  filesEqual,
  loadJson,
  findJsonSchemaFiles,
  fileContainsAnnotation,
  fileContainsParityAnnotation,
} = require("./contractFileIO");

const args = process.argv.slice(2);

/**
 * The single place this script resolves an input, in precedence order:
 * CLI flag, then environment variable, then built-in default. Keeping all
 * three in one function is what stops the same setting being read twice with
 * two different defaults.
 *
 * @param {{name: string, fallback: string, envName?: string}} options
 * @returns {string}
 */
function getArg({ name, fallback, envName }) {
  const idx = args.indexOf(`--${name}`);
  if (idx !== -1) {
    const value = args[idx + 1];
    if (!value || value.startsWith("--")) {
      console.error(`ERROR [MISSING_FLAG_VALUE]: --${name} requires a path.`);
      process.exit(1);
    }
    return value;
  }
  const fromEnv = envName ? process.env[envName] : undefined; // allow-direct-env: this IS the config adapter, the only env read in this script
  return fromEnv || fallback;
}

const contractsDir = path.resolve(getArg({ name: "contracts-dir", fallback: "./contracts" }));
const vendorDir = path.resolve(getArg({ name: "vendor-dir", fallback: "./vendor-contracts" }));
const serverContractsDir = path.resolve(
  getArg({
    name: "server-contracts-dir",
    fallback: path.join(__dirname, "..", "..", "404-solution-server", "contracts"),
    envName: "ABJ404_SERVER_CONTRACTS_DIR",
  })
);

const errors = [];
function fail(msg) {
  errors.push(msg);
}

function validateSchemaFile(schemaPath, label) {
  if (!fileExists(schemaPath)) {
    fail(`${label}: schema file not found: ${schemaPath}`);
    return null;
  }
  let schema;
  try {
    schema = loadJson(schemaPath);
  } catch (e) {
    fail(`${label}: schema is not valid JSON: ${schemaPath} (${e.message})`);
    return null;
  }
  if (typeof schema !== "object" || schema === null) {
    fail(`${label}: schema must be a JSON object: ${schemaPath}`);
    return null;
  }
  if (!schema.type && !schema.$ref && !schema.oneOf && !schema.anyOf && !schema.allOf) {
    fail(`${label}: schema has no type, $ref, or composition keyword: ${schemaPath}`);
    return null;
  }
  return schema;
}

function validateFixtures(schema, schemaPath, fixtures, baseDir, label) {
  let Ajv;
  const resolvePaths = [process.cwd(), __dirname];
  try {
    Ajv = require(require.resolve("ajv/dist/2020", { paths: resolvePaths }));
  } catch {
    try {
      Ajv = require(require.resolve("ajv", { paths: resolvePaths }));
    } catch {
      // allow-silent-catch: AJV not installed; skip fixture validation with warning
      console.log(
        `  WARN: ajv not installed, skipping fixture validation for ${label}. Install with: npm install --save-dev ajv`
      );
      for (const f of [...(fixtures.valid || []), ...(fixtures.invalid || [])]) {
        const fp = path.resolve(baseDir, f);
        if (!fileExists(fp)) {
          fail(`${label}: fixture file not found: ${f}`);
        }
      }
      return;
    }
  }

  const ajv = new Ajv({ allErrors: true, strict: false });
  let validate;
  try {
    validate = compileJsonSchema(ajv, schema);
  } catch (e) {
    fail(`${label}: schema compilation failed: ${e.message}`);
    return;
  }

  for (const f of fixtures.valid || []) {
    const fp = path.resolve(baseDir, f);
    if (!fileExists(fp)) {
      fail(`${label}: valid fixture not found: ${f}`);
      continue;
    }
    let data;
    try {
      data = loadJson(fp);
    } catch (e) {
      fail(`${label}: valid fixture is not valid JSON: ${f} (${e.message})`);
      continue;
    }
    if (!validate(data)) {
      const fieldErrors = validate.errors
        .map((e) => `  ${e.instancePath || "/"}: ${e.message}`)
        .join("\n");
      fail(`${label}: valid fixture FAILED schema validation: ${f}\n${fieldErrors}`);
    }
  }

  for (const f of fixtures.invalid || []) {
    const fp = path.resolve(baseDir, f);
    if (!fileExists(fp)) {
      fail(`${label}: invalid fixture not found: ${f}`);
      continue;
    }
    let data;
    try {
      data = loadJson(fp);
    } catch (e) {
      fail(`${label}: invalid fixture is not valid JSON: ${f} (${e.message})`);
      continue;
    }
    if (validate(data)) {
      fail(`${label}: invalid fixture PASSED schema validation (should have failed): ${f}`);
    }
  }
}

function compileJsonSchema(ajv, schema) {
  try {
    return ajv.compile(schema);
  } catch (e) {
    const message = e && e.message ? e.message : "";
    if (
      schema &&
      schema.$schema === "https://json-schema.org/draft/2020-12/schema" &&
      /no schema with key or ref/.test(message)
    ) {
      const fallbackSchema = JSON.parse(JSON.stringify(schema));
      delete fallbackSchema.$schema;
      return ajv.compile(fallbackSchema);
    }
    throw e;
  }
}

function validateTestFiles(testSpec, contractId, side, label, annotationName = "contract") {
  const tests = Array.isArray(testSpec) ? testSpec : [testSpec];
  for (const t of tests) {
    const tp = path.resolve(t);
    if (!fileExists(tp)) {
      const relTp = path.resolve(process.cwd(), t);
      if (!fileExists(relTp)) {
        fail(`${label}: ${side} test file not found: ${t}`);
        continue;
      }
      if (!fileContainsAnnotation(relTp, contractId, annotationName)) {
        fail(`${label}: ${side} test file missing @${annotationName} ${contractId} annotation: ${t}`);
      }
      continue;
    }
    if (!fileContainsAnnotation(tp, contractId, annotationName)) {
      fail(`${label}: ${side} test file missing @${annotationName} ${contractId} annotation: ${t}`);
    }
  }
}

function validateParityTestFiles(testSpec, contractId, label) {
  const tests = Array.isArray(testSpec) ? testSpec : [testSpec];
  for (const t of tests) {
    const tp = path.resolve(t);
    const resolved = fileExists(tp) ? tp : path.resolve(process.cwd(), t);
    if (!fileExists(resolved)) {
      fail(`${label}: parityTest file not found: ${t}`);
      continue;
    }
    if (!fileContainsParityAnnotation(resolved, contractId)) {
      fail(`${label}: parityTest file missing @parityTest ${contractId} annotation: ${t}`);
    }
  }
}

function validateBilateralContracts(dir) {
  const manifestPath = path.join(dir, "contracts.json");
  if (!fileExists(manifestPath)) return;

  console.log(`Validating bilateral contracts: ${manifestPath}`);

  let manifest;
  try {
    manifest = loadJson(manifestPath);
  } catch (e) {
    fail(`contracts.json is not valid JSON: ${e.message}`);
    return;
  }

  if (!manifest.contracts || !Array.isArray(manifest.contracts)) {
    fail("contracts.json must have a 'contracts' array");
    return;
  }

  const referencedSchemas = new Set();
  const seenIds = new Set();

  for (const contract of manifest.contracts) {
    const label = `contract '${contract.id}'`;

    if (!contract.id) {
      fail("contract missing 'id' field");
      continue;
    }
    if (seenIds.has(contract.id)) {
      fail(`${label}: duplicate contract id`);
    }
    seenIds.add(contract.id);

    if (!contract.schema) {
      fail(`${label}: missing 'schema' field`);
      continue;
    }

    if (!contract.direction) {
      fail(`${label}: missing 'direction' field`);
    }

    const schemaPath = path.resolve(dir, contract.schema);
    referencedSchemas.add(schemaPath);
    const schema = validateSchemaFile(schemaPath, label);

    if (contract.producer) {
      if (contract.producer.test) {
        validateTestFiles(contract.producer.test, contract.id, "producer", label);
      } else {
        fail(`${label}: producer missing 'test' field`);
      }
    } else if (contract.direction !== "server-to-client") {
      fail(`${label}: bilateral contract missing 'producer'`);
    }

    if (contract.consumer) {
      if (contract.consumer.test) {
        validateTestFiles(contract.consumer.test, contract.id, "consumer", label);
      } else {
        fail(`${label}: consumer missing 'test' field`);
      }
    } else if (contract.direction !== "client-to-server") {
      fail(`${label}: bilateral contract missing 'consumer'`);
    }

    if (contract.fixtures && schema) {
      validateFixtures(schema, schemaPath, contract.fixtures, dir, label);
    } else if (!contract.fixtures) {
      fail(`${label}: missing 'fixtures' (need at least one valid and one invalid)`);
    }

    if (contract.parityTest) {
      validateParityTestFiles(contract.parityTest, contract.id, label);
    } else {
      fail(
        `${label}: missing 'parityTest' field. Every over-the-wire contract needs an integration test that exercises the real client -> real server -> real DB round-trip. See ~/.claude/docs/outbound-contracts.md for the pattern.`
      );
    }
  }

  const allSchemas = findJsonSchemaFiles(path.join(dir, "schemas"));
  for (const s of allSchemas) {
    if (!referencedSchemas.has(s)) {
      const rel = path.relative(dir, s);
      fail(`orphan schema not referenced by any contract: ${rel}`);
    }
  }
}

function validateVendorContracts(dir) {
  const manifestPath = path.join(dir, "vendor-contracts.json");
  if (!fileExists(manifestPath)) return;

  console.log(`Validating vendor contracts: ${manifestPath}`);

  let manifest;
  try {
    manifest = loadJson(manifestPath);
  } catch (e) {
    fail(`vendor-contracts.json is not valid JSON: ${e.message}`);
    return;
  }

  if (!manifest.contracts || !Array.isArray(manifest.contracts)) {
    fail("vendor-contracts.json must have a 'contracts' array");
    return;
  }

  const seenIds = new Set();

  for (const contract of manifest.contracts) {
    const label = `vendor contract '${contract.id}'`;

    if (!contract.id) {
      fail("vendor contract missing 'id' field");
      continue;
    }
    if (seenIds.has(contract.id)) {
      fail(`${label}: duplicate contract id`);
    }
    seenIds.add(contract.id);

    if (!contract.schema) {
      fail(`${label}: missing 'schema' field`);
      continue;
    }

    const schemaPath = path.resolve(dir, contract.schema);
    const schema = validateSchemaFile(schemaPath, label);

    if (contract.owner) {
      if (contract.owner.test) {
        validateTestFiles(contract.owner.test, contract.id, "owner", label);
      } else {
        fail(`${label}: owner missing 'test' field`);
      }
    } else {
      fail(`${label}: missing 'owner'`);
    }

    if (contract.fixtures && schema) {
      validateFixtures(schema, schemaPath, contract.fixtures, dir, label);
    }
  }
}

function validateLegacyFixtures(fixtures, baseDir, label) {
  for (const f of fixtures.legacy || []) {
    const fp = path.resolve(baseDir, f);
    if (!fileExists(fp)) {
      fail(`${label}: legacy fixture not found: ${f}`);
      continue;
    }
    try {
      loadJson(fp);
    } catch (e) {
      fail(`${label}: legacy fixture is not valid JSON: ${f} (${e.message})`);
    }
  }
}

function validateStorageContracts(dir) {
  const manifestPath = path.join(dir, "storage-contracts.json");
  if (!fileExists(manifestPath)) return;

  console.log(`Validating storage contracts: ${manifestPath}`);

  let manifest;
  try {
    manifest = loadJson(manifestPath);
  } catch (e) {
    fail(`storage-contracts.json is not valid JSON: ${e.message}`);
    return;
  }

  if (!manifest.contracts || !Array.isArray(manifest.contracts)) {
    fail("storage-contracts.json must have a 'contracts' array");
    return;
  }

  const referencedSchemas = new Set();
  const seenIds = new Set();

  for (const contract of manifest.contracts) {
    const label = `storage contract '${contract.id}'`;

    if (!contract.id) {
      fail("storage contract missing 'id' field");
      continue;
    }
    if (seenIds.has(contract.id)) {
      fail(`${label}: duplicate contract id`);
    }
    seenIds.add(contract.id);

    if (!contract.schema) {
      fail(`${label}: missing 'schema' field`);
      continue;
    }
    if (!contract.storageKey) {
      fail(`${label}: missing 'storageKey' field`);
    }
    if (!Number.isInteger(contract.currentVersion) || contract.currentVersion < 1) {
      fail(`${label}: currentVersion must be a positive integer`);
    }

    const schemaPath = path.resolve(dir, contract.schema);
    referencedSchemas.add(schemaPath);
    const schema = validateSchemaFile(schemaPath, label);
    if (schema) {
      const required = Array.isArray(schema.required) ? schema.required : [];
      if (!required.includes("_schemaVersion")) {
        fail(`${label}: schema must require _schemaVersion`);
      }
      const versionSchema = schema.properties && schema.properties._schemaVersion;
      if (!versionSchema || versionSchema.type !== "integer") {
        fail(`${label}: schema property _schemaVersion must have type integer`);
      } else if (
        Number.isInteger(contract.currentVersion) &&
        Object.prototype.hasOwnProperty.call(versionSchema, "const") &&
        versionSchema.const !== contract.currentVersion
      ) {
        fail(`${label}: _schemaVersion const must match currentVersion`);
      }
    }

    if (contract.writer && contract.writer.test) {
      validateTestFiles(contract.writer.test, contract.id, "writer", label, "storage-contract");
    } else {
      fail(`${label}: writer missing 'test' field`);
    }

    if (contract.reader && contract.reader.test) {
      validateTestFiles(contract.reader.test, contract.id, "reader", label, "storage-contract");
    } else {
      fail(`${label}: reader missing 'test' field`);
    }

    if (contract.migrations && typeof contract.migrations === "object") {
      for (const [transition, migrationFile] of Object.entries(contract.migrations)) {
        if (!/^[1-9][0-9]*-to-[1-9][0-9]*$/.test(transition)) {
          fail(`${label}: migration key must look like 1-to-2: ${transition}`);
        }
        const migrationPath = path.resolve(dir, String(migrationFile));
        if (!fileExists(migrationPath)) {
          fail(`${label}: migration file not found for ${transition}: ${migrationFile}`);
        }
      }
    }

    if (contract.fixtures && schema) {
      validateFixtures(schema, schemaPath, contract.fixtures, dir, label);
      validateLegacyFixtures(contract.fixtures, dir, label);
    } else if (!contract.fixtures) {
      fail(`${label}: missing 'fixtures' (need valid, invalid, and legacy fixtures)`);
    }
  }

  const allSchemas = findJsonSchemaFiles(path.join(dir, "storage-schemas"));
  for (const s of allSchemas) {
    if (!referencedSchemas.has(s)) {
      const rel = path.relative(dir, s);
      fail(`orphan storage schema not referenced by any storage contract: ${rel}`);
    }
  }
}

const VENDORED_SCHEMA_OWNER_MARKER = "OWNER: 404-solution-server";

/**
 * True when the schema at this path records a FOREIGN repo as its owner.
 *
 * Keying off the file's own ownership record rather than a manifest field means
 * a schema declares its status in one place, and any future vendored schema is
 * picked up automatically. `direction` is deliberately NOT the discriminator:
 * this repo's internal ajax-* contracts are 'client-to-server' too (browser to
 * admin-ajax) and are owned right here.
 *
 * @param {string} schemaPath Absolute path to a JSON Schema file.
 * @returns {boolean}
 */
function isVendoredSchema(schemaPath) {
  if (!fileExists(schemaPath)) return false;
  let schema;
  try {
    schema = loadJson(schemaPath);
  } catch {
    // validateSchemaFile already reported the parse error for this path.
    return false;
  }
  return (
    typeof schema.$comment === "string" &&
    schema.$comment.includes(VENDORED_SCHEMA_OWNER_MARKER)
  );
}

/**
 * Fails when any file this repo VENDORS has drifted from the copy that owns it.
 *
 * The unit is the whole contract, not just its schema: a contract whose schema
 * is foreign-owned has foreign-owned GOLDEN FIXTURES too, because they are the
 * agreed examples of that same wire payload and both repos validate against
 * them. Checking only the schema is what let contracts/fixtures/
 * error-report.valid.json drift for the same reason and in the same commit as
 * the schema itself did.
 *
 * For report.schema.json the owner is 404-solution-server, which compiles its
 * copy as the Fastify body schema; that is the only copy whose constraints can
 * reject a request, while this one is a send-time pre-flight.
 *
 * A missing server checkout is a hard failure rather than a skip: this gate
 * exists precisely because the previous detection
 * (.github/workflows/contract-schema-staleness.yml) never ran once, the GitHub
 * mirror being an allowlist publish that carries no workflows, and a check that
 * stands down when it cannot see the other side reports "no drift" forever.
 *
 * @param {string} dir Absolute path to this repo's contracts directory.
 * @returns {void}
 */
function validateVendoredContractFiles(dir) {
  const manifestPath = path.join(dir, "contracts.json");
  if (!fileExists(manifestPath)) return;

  let manifest;
  try {
    manifest = loadJson(manifestPath);
  } catch (e) {
    // validateBilateralContracts already reported this same parse failure with
    // its own message; re-reporting would double-count one defect. Logged so
    // an early return here is never silent.
    console.log(`  (skipping vendored-file check: contracts.json unreadable: ${e.message})`);
    return;
  }
  if (!manifest.contracts || !Array.isArray(manifest.contracts)) return;

  const shared = new Set();
  for (const contract of manifest.contracts) {
    if (!contract.schema) continue;
    if (!isVendoredSchema(path.resolve(dir, contract.schema))) continue;

    shared.add(contract.schema);
    const fixtures = contract.fixtures || {};
    for (const f of [...(fixtures.valid || []), ...(fixtures.invalid || [])]) {
      shared.add(f);
    }
  }

  const vendored = [...shared].sort();
  if (vendored.length === 0) return;

  console.log(
    `Validating ${vendored.length} vendored contract file(s) against owner: ${serverContractsDir}`
  );

  if (!pathExists(serverContractsDir)) {
    fail(
      `vendored contract owner not found: ${serverContractsDir}. The files here ` +
        `(${vendored.join(", ")}) are verbatim copies owned by 404-solution-server; ` +
        `clone it beside this repo, or pass --server-contracts-dir / set ` +
        `ABJ404_SERVER_CONTRACTS_DIR, so drift can actually be detected.`
    );
    return;
  }

  for (const rel of vendored) {
    const localPath = path.resolve(dir, rel);
    const ownerPath = path.resolve(serverContractsDir, rel);

    if (!fileExists(ownerPath)) {
      fail(
        `vendored contract file '${rel}' has no owning copy at ${ownerPath}. Either ` +
          `the server removed it (this copy must go too) or the path moved.`
      );
      continue;
    }
    if (!fileExists(localPath)) {
      fail(`vendored contract file '${rel}' not found: ${localPath}`);
      continue;
    }

    if (!filesEqual(localPath, ownerPath)) {
      fail(
        `vendored contract file '${rel}' has drifted from its owner. This copy is ` +
          `never edited directly: make the change in 404-solution-server, then run\n` +
          `      cp ${ownerPath} ${localPath}\n` +
          `    and, for report.schema.json, update EXPECTED_SHA256 in ` +
          `tests-js/report-schema-drift.test.js here and in ` +
          `tests/report-schema-drift.test.js there.`
      );
    }
  }
}

// --- Main ---

if (!pathExists(contractsDir) && !pathExists(vendorDir)) {
  process.exit(0);
}

validateBilateralContracts(contractsDir);
validateVendorContracts(vendorDir);
validateStorageContracts(contractsDir);
validateVendoredContractFiles(contractsDir);

if (errors.length > 0) {
  console.error(`\n${errors.length} contract validation error(s):\n`);
  for (const e of errors) {
    console.error(`  FAIL: ${e}`);
  }
  console.error("");
  process.exit(1);
} else {
  const contractCount =
    (fileExists(path.join(contractsDir, "contracts.json")) ? loadJson(path.join(contractsDir, "contracts.json")).contracts.length : 0) +
    (fileExists(path.join(vendorDir, "vendor-contracts.json")) ? loadJson(path.join(vendorDir, "vendor-contracts.json")).contracts.length : 0);
  const storageContractCount =
    fileExists(path.join(contractsDir, "storage-contracts.json"))
      ? loadJson(path.join(contractsDir, "storage-contracts.json")).contracts.length
      : 0;
  if (storageContractCount > 0) {
    console.log(`  OK: ${contractCount} contract(s), ${storageContractCount} storage contract(s) validated`);
  } else {
    console.log(`  OK: ${contractCount} contract(s) validated`);
  }
  process.exit(0);
}
