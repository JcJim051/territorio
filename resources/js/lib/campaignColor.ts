export function campaignContrast(color: string): '#102A33' | '#FFFFFF' {
    const hex = /^#[0-9A-Fa-f]{6}$/.test(color) ? color.slice(1) : '0D4D4B';
    const channels = [0, 2, 4].map((offset) => Number.parseInt(hex.slice(offset, offset + 2), 16) / 255);
    const [red, green, blue] = channels.map((channel) => (
        channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
    ));
    const luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue;

    return luminance > 0.42 ? '#102A33' : '#FFFFFF';
}
