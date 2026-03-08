pub fn alloc(size: usize) -> *mut u8 {
    loop {}
}

pub fn dealloc(ptr: *mut u8) {
    loop {}
}

pub fn realloc(ptr: *mut u8, new_size: usize) -> *mut u8 {
    loop {}
}

pub struct Vec<T> {
    ptr: *mut u8,
    cap: usize,
    len: usize,
}

pub struct VecIter<T> {
    vec_ref: &Vec<T>,
    idx: usize,
}

impl<T> Vec<T> {
    pub fn new() -> Vec<T> {
        Vec {
            ptr: 0 as *mut u8,
            cap: 0,
            len: 0,
        }
    }

    pub fn push(&mut self, x: T) {
        if self.len >= self.cap {
            let new_cap = if self.cap == 0 { 1 } else { self.cap * 2 };
            let new_size = new_cap * size_of::<T>();
            if self.ptr == 0 as *mut u8 {
                self.ptr = alloc(new_size);
            } else {
                self.ptr = realloc(self.ptr, new_size);
            }
            self.cap = new_cap;
        }
        self[self.len] = x;
        self.len = self.len + 1;
    }

    pub fn iter(&self) -> VecIter<T> {
        VecIter { vec_ref: self, idx: 0 }
    }
}

impl<T> VecIter<T> {
    pub fn next(&mut self) -> Option<T> {
        if self.idx >= self.vec_ref.len {
            Option::<T>::None
        } else {
            let i = self.idx;
            self.idx = self.idx + 1;
            Option::<T>::Some(self.vec_ref[i])
        }
    }
}

pub struct VecI32 {
    ptr: *mut u8,
    cap: usize,
    len: usize,
}

impl VecI32 {
    pub fn new() -> VecI32 {
        VecI32 {
            ptr: 0 as *mut u8,
            cap: 0,
            len: 0,
        }
    }

    pub fn push(&mut self, x: i32) {
        if self.len >= self.cap {
            let new_cap = if self.cap == 0 { 1 } else { self.cap * 2 };
            let new_size = new_cap * 8;
            if self.ptr == 0 as *mut u8 {
                self.ptr = alloc(new_size);
            } else {
                self.ptr = realloc(self.ptr, new_size);
            }
            self.cap = new_cap;
        }
        self[self.len] = x;
        self.len = self.len + 1;
    }
}
