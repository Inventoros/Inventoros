// Report-specific pictograms, drawn on one 48-unit grid. Utility/navigation
// icons stay in Lucide; these larger diagrams describe inventory work.
export const reportGlyphs = {
    valuation: {
        ink: ['M6 12H28V38H6V12 M6 22H28 M6 30H28 M12 12V38', 'M44 30A8 8 0 1 1 28 30A8 8 0 1 1 44 30'],
        accent: ['M36 25A5 5 0 0 1 36 35'],
        solid: ['M15 15H25V19H15Z'],
    },
    movement: {
        ink: ['M6 16H18V34H6V16 M30 16H42V34H30V16 M6 24H18 M30 24H42'],
        accent: ['M10 8H38 M14 4L10 8L14 12', 'M10 42H38 M34 38L38 42L34 46'],
        solid: ['M9 27H15V31H9Z', 'M33 19H39V23H33Z'],
    },
    sales: {
        ink: ['M8 6H30L38 14V42H8V6 M30 6V14H38', 'M14 34H32 M16 28V34 M24 24V34 M32 18V34'],
        accent: ['M14 22L22 18L28 20L34 14'],
        solid: ['M30 26H34V32H30Z'],
    },
    shortage: {
        ink: ['M6 12H16V26H6V12', 'M22 12H32 M22 18H32 M38 12H42', 'M6 36H42 M40 30V42'],
        accent: ['M6 36H16'],
        solid: ['M9 15H13V23H9Z', 'M14 34H18V38H14Z'],
    },
    categories: {
        ink: ['M6 8H42V40H6V8 M6 24H42 M24 8V40', 'M10 28H18V36H10V28 M28 12H36V20H28V12 M28 28H36V36H28V28'],
        accent: ['M10 12H20V20H10V12'],
        solid: ['M13 15H17V17H13Z'],
    },
    idle: {
        ink: ['M8 18L22 10L36 18V34L22 42L8 34V18 M8 18L22 26L36 18 M22 26V42', 'M44 12A8 8 0 1 1 28 12A8 8 0 1 1 44 12'],
        accent: ['M36 8V12H40', 'M14 28V32 M18 30V34'],
        solid: ['M12 18L22 12L26 14L16 20Z'],
    },
    turnover: {
        ink: ['M16 14H32V34H16V14 M16 22H32 M22 14V34'],
        accent: ['M6 24C6 13 13 6 24 6H32 M28 2L32 6L28 10', 'M42 24C42 35 35 42 24 42H16 M20 38L16 42L20 46'],
        solid: ['M25 25H29V31H25Z'],
    },
    margin: {
        ink: ['M6 10H40V18H6V10 M6 28H28V36H6V28'],
        accent: ['M28 42H40 M28 39V45 M40 39V45'],
        solid: ['M30 13H37V15H30Z'],
    },
    locations: {
        ink: ['M4 22L14 14L24 22V40H4V22 M10 40V30H18V40', 'M28 12L36 6L44 12V26H28V12 M33 26V18H39V26'],
        accent: ['M26 34H36V30', 'M32 34L36 30L40 34'],
        solid: ['M8 24H12V28H8Z', 'M32 12H36V16H32Z'],
    },
    abc: {
        ink: ['M6 14H14V40H6V14 M20 24H28V40H20V24 M34 32H42V40H34V32'],
        accent: ['M6 44H42'],
        solid: ['M9 17H11V37H9Z'],
        labels: [{ x: 10, y: 10, text: 'A' }, { x: 24, y: 20, text: 'B' }, { x: 38, y: 28, text: 'C' }],
    },
    receivables: {
        ink: ['M8 6H34V42L29 38L24 42L19 38L14 42L8 38V6', 'M14 14H28 M14 20H24', 'M44 30A8 8 0 1 1 28 30A8 8 0 1 1 44 30'],
        accent: ['M36 26V30H40'],
        solid: ['M14 27H24V31H14Z'],
    },
    expiry: {
        ink: ['M6 14H28V40H6V14 M10 10H32V26 M14 6H36V18', 'M30 22H42 M30 42H42 M32 22C32 29 40 35 40 42 M40 22C40 29 32 35 32 42'],
        accent: ['M12 22H22 M12 28H18'],
        solid: ['M34 38L36 35L38 38Z'],
    },
    builder: {
        ink: ['M6 6H34L42 14V42H6V6 M34 6V14H42', 'M12 14H24V22H12V14 M12 28H22V36H12V28 M28 18H36V24H28V18'],
        accent: ['M28 34H38 M33 29V39'],
        solid: ['M15 17H21V19H15Z'],
    },
};
