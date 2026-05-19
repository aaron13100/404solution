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

function fileExists(p) {
  try {
    return fs.statSync(p).isFile();
  } catch {
    return false; // allow-silent-catch: stat failure means file does not exist
  }
}

function loadJson(p) {
  return JSON.parse(fs.readFileSync(p, "utf8"));
}

function findJsonSchemaFiles(dir) {
  const results = [];
  if (!fs.existsSync(dir)) return results;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      results.push(...findJsonSchemaFiles(full));
    } else if (entry.name.endsWith(".schema.json")) {
      results.push(full);
    }
  }
  return results;
}

function fileContainsAnnotation(filePath, contractId) {
  const content = fs.readFileSync(filePath, "utf8");
  const pattern = new RegExp(`@contract\\s+${contractId.replace(/-/g, "\\-")}\\b`);
  return pattern.test(content);
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
    validate = ajv.compile(schema);
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

function validateTestFiles(testSpec, contractId, side, label) {
  const tests = Array.isArray(testSpec) ? testSpec : [testSpec];
  for (const t of tests) {
    const tp = path.resolve(t);
    if (!fileExists(tp)) {
      const relTp = path.resolve(process.cwd(), t);
      if (!fileExists(relTp)) {
        fail(`${label}: ${side} test file not found: ${t}`);
        continue;
      }
      if (!fileContainsAnnotation(relTp, contractId)) {
        fail(`${label}: ${side} test file missing @contract ${contractId} annotation: ${t}`);
      }
      continue;
    }
    if (!fileContainsAnnotation(tp, contractId)) {
      fail(`${label}: ${side} test file missing @contract ${contractId} annotation: ${t}`);
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

// --- Main ---

if (!fs.existsSync(contractsDir) && !fs.existsSync(vendorDir)) {
  process.exit(0);
}

validateBilateralContracts(contractsDir);
validateVendorContracts(vendorDir);

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
  console.log(`  OK: ${contractCount} contract(s) validated`);
  process.exit(0);
}
