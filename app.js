const DATA_URL = 'https://rovermdashfaq.github.io/martbook-data/data.json';
let currentUser = null;

fetch(DATA_URL)
  .then(res => res.json())
  .then(data => { window.MARTBOOK_DATA = data; renderFeed(); })
  .catch(err => console.error(err));

// Render Feed
function renderFeed() {
  const container = document.getElementById('martbook-container');
  container.innerHTML = '';

  // Users
  const usersSection = document.createElement('div');
  usersSection.innerHTML = `<div class="section-title">Users</div>`;
  window.MARTBOOK_DATA.users.forEach(u => {
    const card = document.createElement('div');
    card.className = 'user-card';
    card.innerHTML = `<h3>${u.name}</h3><p>Email: ${u.email}</p><p>City: ${u.city}</p>`;
    usersSection.appendChild(card);
  });
  container.appendChild(usersSection);

  // Posts
  const postsSection = document.createElement('div');
  postsSection.innerHTML = `<div class="section-title">Posts</div>`;
  window.MARTBOOK_DATA.posts.forEach((p, index) => {
    const card = document.createElement('div');
    card.className = 'post-card';
    card.innerHTML = `
      <h3>${p.user}</h3>
      <p>${p.content}</p>
      <button onclick="likePost(${index})">❤️ ${p.likes||0}</button>
      <button onclick="toggleComment(${index})">💬 ${p.comments?.length||0}</button>
      <div id="comments-${index}" style="display:none; margin-top:10px;">
        <input type="text" id="comment-input-${index}" placeholder="Write comment">
        <button onclick="addComment(${index})">Add</button>
        <div id="comment-list-${index}"></div>
      </div>
    `;
    postsSection.appendChild(card);

    // Render existing comments
    const commentList = card.querySelector(`#comment-list-${index}`);
    if(p.comments) {
      p.comments.forEach(c => {
        const div = document.createElement('div');
        div.innerText = `${c.user}: ${c.text}`;
        commentList.appendChild(div);
      });
    }
  });
  container.appendChild(postsSection);

  // Messages
  const msgSection = document.createElement('div');
  msgSection.innerHTML = `<div class="section-title">Messages</div>`;
  window.MARTBOOK_DATA.messages.forEach(m => {
    const card = document.createElement('div');
    card.className = 'message-card';
    card.innerHTML = `<h3>From: ${m.from} → To: ${m.to}</h3><p>${m.content}</p>`;
    msgSection.appendChild(card);
  });
  container.appendChild(msgSection);
}

// Like Post
function likePost(index) {
  if(!window.MARTBOOK_DATA.posts[index].likes) window.MARTBOOK_DATA.posts[index].likes = 0;
  window.MARTBOOK_DATA.posts[index].likes++;
  renderFeed();
}

// Toggle Comment Input
function toggleComment(index) {
  const div = document.getElementById(`comments-${index}`);
  div.style.display = div.style.display==='none'?'block':'none';
}

// Add Comment
function addComment(index) {
  const input = document.getElementById(`comment-input-${index}`);
  const text = input.value.trim();
  if(!text) return;
  if(!window.MARTBOOK_DATA.posts[index].comments) window.MARTBOOK_DATA.posts[index].comments = [];
  window.MARTBOOK_DATA.posts[index].comments.push({user: currentUser.name, text});
  input.value = '';
  renderFeed();
}

// Post Form
document.getElementById('add-post-btn').onclick = () => {
  if(!currentUser) return alert('Login first!');
  const content = document.getElementById('new-post-content').value.trim();
  if(!content) return;
  window.MARTBOOK_DATA.posts.unshift({user: currentUser.name, content, likes:0, comments:[]});
  document.getElementById('new-post-content').value = '';
  renderFeed();
};

// Auth Modal
const modal = document.getElementById('auth-modal');
const modalTitle = document.getElementById('modal-title');
const usernameInput = document.getElementById('username');
const cityInput = document.getElementById('city');
const emailInput = document.getElementById('email');
const submitBtn = document.getElementById('auth-submit');
const closeBtn = document.querySelector('.close');

document.getElementById('login-btn').onclick = () => { modalTitle.innerText = 'Login'; cityInput.style.display = 'none'; modal.style.display = 'block'; };
document.getElementById('register-btn').onclick = () => { modalTitle.innerText = 'Register'; cityInput.style.display = 'block'; modal.style.display = 'block'; };
closeBtn.onclick = () => modal.style.display = 'none';
window.onclick = e => { if(e.target==modal) modal.style.display='none'; };

submitBtn.onclick = () => {
  const name = usernameInput.value.trim();
  const email = emailInput.value.trim();
  const city = cityInput.value.trim();
  if(!name || !email || (modalTitle.innerText=='Register' && !city)) return alert('Fill all fields');

  if(modalTitle.innerText=='Register') {
    currentUser = {name,email,city};
    window.MARTBOOK_DATA.users.push(currentUser);
  } else {
    const user = window.MARTBOOK_DATA.users.find(u=>u.email===email);
    if(!user) return alert('User not found!');
    currentUser = user;
  }
  modal.style.display='none';
  renderFeed();
};
