"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.hoverForComponent = hoverForComponent;
function hoverForComponent(name, catalogue) {
    const documentation = catalogue[name];
    if (!documentation) {
        return `**${name}** — Governed DiSyL Component\n\nThis component is governed by the runtime contract, but detailed documentation is not available in the extension catalogue.`;
    }
    const category = documentation.category || 'Governed DiSyL Component';
    const description = documentation.description || 'No description is available in the extension catalogue.';
    let markdown = `**${name}** — ${category}\n\n${description}`;
    if (documentation.attributes && documentation.attributes.length > 0) {
        markdown += '\n\n**Attributes:**\n';
        for (const attribute of documentation.attributes) {
            markdown += `\n- \`${attribute}\``;
        }
    }
    else {
        markdown += '\n\n**Attributes:** No attributes are documented in the extension catalogue.';
    }
    return markdown;
}
//# sourceMappingURL=componentDocs.js.map