(function () {
'use strict';

window.FootballGoalRaceValidator = {
    validateBoard(board) {
        const errors = [];
        if (!board || board.type !== 'football_goal_race') errors.push('Tablero incorrecto.');
        ['blue', 'red'].forEach((side) => {
            const path = board?.paths?.[side];
            if (!Array.isArray(path) || path.length !== 31) errors.push(`El recorrido ${side} debe tener 31 posiciones.`);
            (path || []).forEach((p, i) => {
                if (p.n !== i) errors.push(`Posicion ${i} invalida en ${side}.`);
                if (p.x < 0 || p.x > 100 || p.y < 0 || p.y > 100) errors.push(`Coordenadas fuera de rango en ${side} ${i}.`);
            });
        });
        return errors;
    },
};
})();
