const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

const beats = { rock: 'scissors', paper: 'rock', scissors: 'paper' };
const PORT = process.env.PORT || 4000;
const MAX_PLAYERS = Number(process.env.MAX_PLAYERS || 4);
const allowedOrigins = process.env.CORS_ORIGIN
  ? process.env.CORS_ORIGIN.split(',').map((origin) => origin.trim())
  : ['*'];

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: allowedOrigins }
});

const parties = new Map();

app.get('/', (_req, res) => {
  res.json({ status: 'Rock Paper Scissors Party+ server online' });
});

function generatePartyCode() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let code = '';
  for (let i = 0; i < 5; i += 1) {
    code += alphabet[Math.floor(Math.random() * alphabet.length)];
  }
  return code;
}

function createPartySnapshot(party) {
  return {
    code: party.code,
    hostId: party.hostId,
    maxPlayers: party.maxPlayers,
    slotsAvailable: Math.max(party.maxPlayers - party.players.length, 0),
    players: party.players.map((p) => ({
      id: p.id,
      name: p.name,
      choiceLocked: p.choiceLocked,
      choice: p.choice
    }))
  };
}

function broadcastPartyUpdate(party) {
  const snapshot = createPartySnapshot(party);
  party.players.forEach((player) => {
    io.to(player.id).emit('partyUpdate', snapshot);
  });
}

function determineWinner(moves) {
  const uniqueChoices = [...new Set(moves.map((m) => m.choice))];
  if (uniqueChoices.length !== 2) {
    return { winnerId: null, tie: true };
  }
  const [choiceA, choiceB] = uniqueChoices;
  const winningChoice = beats[choiceA] === choiceB
    ? choiceA
    : beats[choiceB] === choiceA
      ? choiceB
      : null;
  if (!winningChoice) {
    return { winnerId: null, tie: true };
  }
  const winner = moves.find((m) => m.choice === winningChoice);
  return { winnerId: winner ? winner.id : null, tie: false };
}

function leaveParty(socket) {
  const code = socket.data.partyCode;
  if (!code) return;
  const party = parties.get(code);
  if (!party) {
    socket.data.partyCode = null;
    return;
  }
  party.players = party.players.filter((p) => p.id !== socket.id);
  if (party.hostId === socket.id && party.players.length) {
    party.hostId = party.players[0].id;
  }
  if (!party.players.length) {
    parties.delete(code);
  } else {
    broadcastPartyUpdate(party);
  }
  socket.data.partyCode = null;
}

io.on('connection', (socket) => {
  socket.data.partyCode = null;

  socket.on('createParty', ({ name }) => {
    leaveParty(socket);
    const displayName = (name || 'Player').trim().slice(0, 20) || 'Player';
    let code;
    do {
      code = generatePartyCode();
    } while (parties.has(code));

    const party = {
      code,
      hostId: socket.id,
      maxPlayers: MAX_PLAYERS,
      players: [{
        id: socket.id,
        name: displayName,
        choice: null,
        choiceLocked: false
      }]
    };
    parties.set(code, party);
    socket.data.partyCode = code;
    socket.emit('partyJoined', { code });
    broadcastPartyUpdate(party);
  });

  socket.on('joinParty', ({ code, name }) => {
    leaveParty(socket);
    const upperCode = (code || '').trim().toUpperCase();
    if (!upperCode || !parties.has(upperCode)) {
      socket.emit('errorMessage', 'Party code not found.');
      return;
    }
    const party = parties.get(upperCode);
    if (party.players.length >= party.maxPlayers) {
      socket.emit('errorMessage', 'Party is full.');
      return;
    }
    const displayName = (name || 'Player').trim().slice(0, 20) || 'Player';
    party.players.push({
      id: socket.id,
      name: displayName,
      choice: null,
      choiceLocked: false
    });
    socket.data.partyCode = upperCode;
    socket.emit('partyJoined', { code: upperCode });
    broadcastPartyUpdate(party);
  });

  socket.on('makeMove', ({ code, choice }) => {
    const party = parties.get(code);
    if (!party) {
      socket.emit('errorMessage', 'Party no longer exists.');
      return;
    }
    const player = party.players.find((p) => p.id === socket.id);
    if (!player) {
      socket.emit('errorMessage', 'You are not part of this party.');
      return;
    }
    if (!['rock', 'paper', 'scissors'].includes(choice)) {
      socket.emit('errorMessage', 'Invalid move.');
      return;
    }
    player.choice = choice;
    player.choiceLocked = true;
    broadcastPartyUpdate(party);

    const activePlayers = party.players.filter((p) => p.choiceLocked);
    if (party.players.length >= 2 && activePlayers.length === party.players.length) {
      const moves = party.players.map((p) => ({
        id: p.id,
        name: p.name,
        choice: p.choice
      }));
      const { winnerId, tie } = determineWinner(moves);
      party.players.forEach((p) => {
        p.choice = null;
        p.choiceLocked = false;
      });
      const payload = { moves, winnerId, tie: !!tie };
      party.players.forEach((p) => {
        io.to(p.id).emit('roundResult', payload);
      });
      broadcastPartyUpdate(party);
    }
  });

  socket.on('chatMessage', ({ code, text }) => {
    const message = (text || '').trim();
    const party = parties.get(code);
    if (!party || !message) return;
    const player = party.players.find((p) => p.id === socket.id);
    if (!player) return;
    const payload = {
      sender: player.name,
      text: message,
      timestamp: Date.now()
    };
    party.players.forEach((p) => {
      io.to(p.id).emit('chatMessage', payload);
    });
  });

  socket.on('disconnect', () => {
    leaveParty(socket);
  });
});

server.listen(PORT, () => {
  console.log(`Rock Paper Scissors Party+ server running on port ${PORT}`);
});
