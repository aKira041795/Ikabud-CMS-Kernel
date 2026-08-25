document.addEventListener('DOMContentLoaded',()=>{
  const createForm=document.getElementById('user-create-form'),list=document.getElementById('users-list'),status=document.getElementById('users-status'),reload=document.getElementById('users-reload');
  const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const show=message=>{status.textContent=message||'';};
  function render(users){
    if(!users.length){list.innerHTML='<p class="muted">No active users.</p>';return;}
    list.innerHTML=users.map(user=>`<form class="user-row grid" data-user-id="${Number(user.id)}" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));align-items:end;border-top:1px solid var(--line);padding-top:1rem">
      <label>Full name<input name="full_name" maxlength="255" required value="${escapeHtml(user.full_name)}"></label>
      <label>Email<input name="email" type="email" required value="${escapeHtml(user.email)}"></label>
      <label>Role<select name="role"><option value="member"${user.role==='member'?' selected':''}>Member</option><option value="admin"${user.role==='admin'?' selected':''}>Admin</option><option value="owner"${user.role==='owner'?' selected':''}>Owner</option></select></label>
      <label>Status<select name="is_active"><option value="true"${user.is_active?' selected':''}>Active</option><option value="false"${!user.is_active?' selected':''}>Inactive</option></select></label>
      <span><button class="button save-user" type="submit">Save</button> <button class="button danger delete-user" type="button"${Number(user.id)===Number(window.HARPP_CURRENT_USER_ID)?' disabled title="You cannot delete yourself"':''}>Delete</button></span>
    </form>`).join('');
  }
  async function load(){reload.disabled=true;try{const result=await Harpp.fetch('/api/v1/harpp/users');render(result.data.users||[]);}catch(error){list.innerHTML='';show(error.message);}finally{reload.disabled=false;}}
  createForm.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"]');button.disabled=true;try{await Harpp.fetch('/api/v1/harpp/users',{method:'POST',body:Object.fromEntries(new FormData(form))});form.reset();show('User created.');await load();}catch(error){show(error.message);}finally{button.disabled=false;}});
  list.addEventListener('submit',async event=>{event.preventDefault();const form=event.target;if(!form.matches('.user-row'))return;const button=form.querySelector('.save-user');button.disabled=true;try{await Harpp.fetch(`/api/v1/harpp/users/${form.dataset.userId}`,{method:'PUT',body:Object.fromEntries(new FormData(form))});show('User updated.');await load();}catch(error){show(error.message);}finally{button.disabled=false;}});
  list.addEventListener('click',async event=>{const button=event.target.closest('.delete-user');if(!button)return;const form=button.closest('.user-row');if(!confirm('Soft-delete this user? They will no longer be able to sign in.'))return;button.disabled=true;try{await Harpp.fetch(`/api/v1/harpp/users/${form.dataset.userId}`,{method:'DELETE'});show('User deleted.');await load();}catch(error){show(error.message);button.disabled=false;}});
  reload.addEventListener('click',load);load();
});
