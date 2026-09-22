// Platzhalter für Importe, die Jest nicht lesen kann: Vorlagen, SCSS und Vite-eigenes wie
// `import.meta.glob`. Jeder Name daraus antwortet mit einer leeren Liste — das ist die einzige
// Form, mit der aufrufende Module sowohl zählen als auch durchlaufen können, ohne zu stürzen.
module.exports = new Proxy({}, { get: () => [] });
