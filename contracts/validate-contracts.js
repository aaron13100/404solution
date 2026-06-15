#!/usr/bin/env node

// Pre-commit gate for outbound contracts.
// Validates contracts.json wiring: schemas exist, fixtures pass/fail correctly,
// test files exist and reference their contract IDs.
//
// Usage: node validate-contracts.js [--contracts-dir <path>] [--vendor-dir <path>]
// Defaults: --contracts-dir ./contracts --vendor-dir ./vendor-contracts
//
// Exit 0: all checks pass (or no contracts directory found)
// Exit 1: validation failure

const fs = require("fs");
const path = require("path");
const {
  fileExists,
  loadJson,
  findJsonSchemaFiles,
  fileContainsAnnotation,
  fileContainsParityAnnotation,
} = require("./contractFileIO");

const args = process.argv.slice(2);
function getArg(name, fallback) {
  const idx = args.indexOf(`--${name}`);
  return idx !== -1 && args[idx + 1] ? args[idx + 1] : fallback;
}

const contractsDir = path.resolve(getArg("contracts-dir", "./contracts"));
const vendorDir = path.resolve(getArg("vendor-dir", "./vendor-contracts"));

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

// --- Main ---

if (!fs.existsSync(contractsDir) && !fs.existsSync(vendorDir)) {
  process.exit(0);
}

validateBilateralContracts(contractsDir);
validateVendorContracts(vendorDir);
validateStorageContracts(contractsDir);

if (errors.length > 0) {
  console.error(`\n${errors.length} contract validation error(s):\n`);
  for (const e of errors) {
    console.error(`  FAIL: ${e}`);
  }
  console.error("");
  process.exit(1);
} else {
  const contractCount =
    (fs.existsSync(path.join(contractsDir, "contracts.json")) ? loadJson(path.join(contractsDir, "contracts.json")).contracts.length : 0) +
    (fs.existsSync(path.join(vendorDir, "vendor-contracts.json")) ? loadJson(path.join(vendorDir, "vendor-contracts.json")).contracts.length : 0);
  const storageContractCount =
    fs.existsSync(path.join(contractsDir, "storage-contracts.json"))
      ? loadJson(path.join(contractsDir, "storage-contracts.json")).contracts.length
      : 0;
  if (storageContractCount > 0) {
    console.log(`  OK: ${contractCount} contract(s), ${storageContractCount} storage contract(s) validated`);
  } else {
    console.log(`  OK: ${contractCount} contract(s) validated`);
  }
  process.exit(0);
}
