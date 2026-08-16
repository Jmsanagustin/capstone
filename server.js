const express = require('express');
const mysql = require('mysql2');
const cors = require('cors');

// Create an Express application
const app = express();
app.use(cors());
app.use(express.json());

// Database connection
const db = mysql.createConnection({
  host: 'localhost',
  user: 'root', // Replace with your MySQL username
  password: '', // Replace with your MySQL password
  database: 'school', // Replace with your database name
});

// Connect to the database
db.connect((err) => {
  if (err) throw err;
  console.log('Connected to the database');
});

// Endpoint to fetch students based on year and section
app.get('/students', (req, res) => {
  const { year, section } = req.query;

  const query = 'SELECT name FROM students WHERE year = ? AND section = ?';
  
  db.query(query, [year, section], (err, results) => {
    if (err) {
      res.status(500).json({ error: 'Database query failed' });
      return;
    }
    res.json(results);
  });
});

// Start the server
const PORT = process.env.PORT || 5000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
