import {createHash} from 'node:crypto';

export const sha256 = value => createHash('sha256').update(value).digest('hex');

// Read CSS rule boundaries without confusing comments, strings or data URLs
// with rules. This is a conservative selector-preservation check, not a CSS
// validator or a substitute for testing rendered pages in both directions.
function boundary(css, start, delimiters) {
  let quote = '', comment = false, parentheses = 0, brackets = 0;
  for (let index = start; index < css.length; index++) {
    const character = css[index], next = css[index + 1];
    if (comment) {
      if (character === '*' && next === '/') { comment = false; index++; }
      continue;
    }
    if (character === '\\') { index++; continue; }
    if (quote) { if (character === quote) quote = ''; continue; }
    if (character === '/' && next === '*') { comment = true; index++; continue; }
    if (character === '"' || character === "'") { quote = character; continue; }
    if (character === '(') parentheses++;
    if (character === ')') parentheses--;
    if (character === '[') brackets++;
    if (character === ']') brackets--;
    if (!parentheses && !brackets && delimiters.includes(character)) return index;
  }
  return css.length;
}

function normalize(prelude) {
  let clean = '', quote = '';
  for (let index = 0; index < prelude.length; index++) {
    const character = prelude[index], next = prelude[index + 1];
    if (character === '\\') { clean += character + (next || ''); index++; continue; }
    if (quote) {
      clean += character;
      if (character === quote) quote = '';
      continue;
    }
    if (character === '"' || character === "'") quote = character;
    if (character === '/' && next === '*') {
      const end = prelude.indexOf('*/', index + 2);
      if (end < 0) throw new Error('Unclosed CSS comment');
      index = end + 1;
    }
    else clean += character;
  }
  const parts = clean.match(/"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^"']+/g) || [];
  return parts.map(part => /^["']/.test(part) ? part : part
    .replace(/\s+/g, ' ')
    .replace(/\s*([>+~,():=])\s*/g, '$1')).join('').trim();
}

function closingBrace(css, start) {
  let depth = 1, index = start;
  while (depth && index < css.length) {
    const end = boundary(css, index, '{}');
    if (end === css.length) throw new Error('Unclosed CSS block');
    depth += css[end] === '{' ? 1 : -1;
    index = end + 1;
  }
  return index - 1;
}

export function selectors(css, contexts = []) {
  const result = new Set();
  let start = 0;
  while (start < css.length) {
    const end = boundary(css, start, '{;}');
    if (end === css.length) break;
    if (css[end] === '}') throw new Error('Unexpected CSS closing brace');
    if (css[end] === ';') { start = end + 1; continue; }
    const prelude = normalize(css.slice(start, end));
    const close = closingBrace(css, end + 1);
    if (/^@(media|supports|layer|container|document|scope|starting-style)\b/i.test(prelude)) {
      for (const selector of selectors(css.slice(end + 1, close), [...contexts, prelude])) {
        result.add(selector);
      }
    }
    else if (prelude && !prelude.startsWith('@')) {
      let position = 0;
      while (position < prelude.length) {
        const comma = boundary(prelude, position, ',');
        const selector = normalize(prelude.slice(position, comma));
        result.add([...contexts, selector].join(' | '));
        position = comma + 1;
      }
    }
    start = close + 1;
  }
  return result;
}

export function compareCss(name, canonical, candidate) {
  const before = selectors(canonical.toString('utf8'));
  const after = selectors(candidate.toString('utf8'));
  return {
    file: name,
    canonicalBytes: Buffer.byteLength(canonical),
    candidateBytes: Buffer.byteLength(candidate),
    canonicalSha256: sha256(canonical),
    candidateSha256: sha256(candidate),
    changed: sha256(canonical) !== sha256(candidate),
    missingSelectors: [...before].filter(selector => !after.has(selector)).sort(),
    addedSelectors: [...after].filter(selector => !before.has(selector)).sort(),
  };
}
