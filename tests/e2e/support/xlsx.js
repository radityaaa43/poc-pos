import { inflateRawSync } from 'node:zlib';

// An .xlsx file is a zip of XML parts. Returns the text of every part, so a step can look for a cell value.
// shortcut: reads the zip central directory only (deflate or stored entries, no zip64), enough for Maatwebsite exports.
export function xlsxText(buffer) {
    let offset = buffer.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    const entries = buffer.readUInt16LE(offset + 10);
    offset = buffer.readUInt32LE(offset + 16);

    const parts = {};
    for (let i = 0; i < entries; i++) {
        const method = buffer.readUInt16LE(offset + 10);
        const size = buffer.readUInt32LE(offset + 20);
        const nameLength = buffer.readUInt16LE(offset + 28);
        const extraLength = buffer.readUInt16LE(offset + 30);
        const commentLength = buffer.readUInt16LE(offset + 32);
        const local = buffer.readUInt32LE(offset + 42);
        const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength);
        const dataStart = local + 30 + buffer.readUInt16LE(local + 26) + buffer.readUInt16LE(local + 28);
        const raw = buffer.subarray(dataStart, dataStart + size);
        parts[name] = (method === 8 ? inflateRawSync(raw) : raw).toString('utf8');
        offset += 46 + nameLength + extraLength + commentLength;
    }

    return Object.values(parts).join('\n');
}
