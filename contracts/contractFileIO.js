/**
 * contractFileIO: filesystem-access layer for the contract validation gate.
 *
 * Pure data-access concern. Reads files and directories from disk and parses
 * their contents. Contains no business/validation logic; callers in
 * validate-contracts.js decide what the read data means for contract validity.
 *
 * Exposes:
 *   fileExists(p)                                  -> boolean
 *   loadJson(p)                                    -> parsed JSON (throws on bad JSON)
 *   findJsonSchemaFiles(dir)                       -> string[] of *.schema.json paths
 *   fileContainsAnnotation(filePath, id, name?)    -> boolean
 *   fileContainsParityAnnotation(filePath, id)     -> boolean
 */

const fs = require("fs");
const path = require("path");

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

function fileContainsAnnotation(filePath, contractId, annotationName = "contract") {
  const content = fs.readFileSync(filePath, "utf8");
  const pattern = new RegExp(`@${annotationName}\\s+${contractId.replace(/-/g, "\\-")}\\b`);
  return pattern.test(content);
}

function fileContainsParityAnnotation(filePath, contractId) {
  const content = fs.readFileSync(filePath, "utf8");
  const pattern = new RegExp(`@parityTest\\s+${contractId.replace(/-/g, "\\-")}\\b`);
  return pattern.test(content);
}

module.exports = {
  fileExists,
  loadJson,
  findJsonSchemaFiles,
  fileContainsAnnotation,
  fileContainsParityAnnotation,
};
