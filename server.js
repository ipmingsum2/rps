const path = require('path');
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

const app = express();
const server = http.createServer(app);
const io = new Server(server);

const rooms = new Map();

function getRoom(roomId) {
  if (!rooms.has(roomId)) {
    rooms.set(roomId, {
      players: new Map(), // socketId -> { name, choice }
      createdAt: Date.now()
    });
  }
  return rooms.get(roomId);
}

function broadcastRoomState(roomId) {
  const room = rooms.get(roomId);
  if (!room) return;

  const players = Array.from(room.players.entries()).map(([socketId, info]) => ({
    socketId,
    name: info.name,
    choice: info.choice ? true : false // only reveal whether choice made
  }));

  io.to(roomId).emit('room_state', {
    roomId,
    players,
    readyCount: players.filter(p => p.choice).length
  });
}

function resolveRound(roomId) {
  const room = rooms.get(roomId);
  if (!room) return;

  const entries = Array.from(room.players.entries());
  if (entries.length !== 2) return;

  const [playerA, playerB] = entries;
  const choiceA = playerA[1].choice;
  const choiceB = playerB[1].choice;

  if (!choiceA || !choiceB) return;

  const outcomes = {
    rock: { rock: 'draw', paper: 'lose', scissors: 'win' },
    paper: { rock: 'win', paper: 'draw', scissors: 'lose' },
    scissors: { rock: 'lose', paper: 'win', scissors: 'draw' }
  };

  const resultA = outcomes[choiceA][choiceB];
  const resultB = resultA === 'win' ? 'lose' : resultA === 'lose' ? 'win' : 'draw';

  io.to(playerA[0]).emit('round_result', {
    you: choiceA,
    opponent: choiceB,
    outcome: resultA
  });
  io.to(playerB[0]).emit('round_result', {
    you: choiceB,
    opponent: choiceA,
    outcome: resultB
  });

  // reset choices for next round
  room.players.get(playerA[0]).choice = null;
  room.players.get(playerB[0]).choice = null;

  broadcastRoomState(roomId);
}

io.on('connection', socket => {
  socket.on('join_room', ({ roomId, name }) => {
    const room = getRoom(roomId);
    if (room.players.size >= 2) {
      socket.emit('room_error', 'Room is full (2 players max).');
      return;
    }

    room.players.set(socket.id, { name: name?.trim() || 'Player', choice: null });
    socket.join(roomId);
    socket.data.roomId = roomId;

    broadcastRoomState(roomId);
  });

  socket.on('leave_room', () => {
    const { roomId } = socket.data;
    if (!roomId) return;
    const room = rooms.get(roomId);
    if (!room) return;

    room.players.delete(socket.id);
    socket.leave(roomId);
    socket.data.roomId = null;

    if (room.players.size === 0) {
      rooms.delete(roomId);
    } else {
      broadcastRoomState(roomId);
    }
  });

  socket.on('player_choice', choice => {
    const { roomId } = socket.data;
    if (!roomId) return;

    const room = rooms.get(roomId);
    if (!room || !room.players.has(socket.id)) return;

    if (!['rock', 'paper', 'scissors'].includes(choice)) return;

    room.players.get(socket.id).choice = choice;
    broadcastRoomState(roomId);
    resolveRound(roomId);
  });

  socket.on('disconnect', () => {
    const { roomId } = socket.data || {};
    if (!roomId) return;
    const room = rooms.get(roomId);
    if (!room) return;

    room.players.delete(socket.id);
    if (room.players.size === 0) {
      rooms.delete(roomId);
    } else {
      broadcastRoomState(roomId);
    }
  });
});

app.use(express.static(path.join(__dirname, 'public')));

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`Rock-Paper-Scissors server running on http://localhost:${PORT}`);
});
// PLS WORK
