pub fn alloc(size: usize) -> *mut u8 {
    loop {}
}

pub fn dealloc(ptr: *mut u8) {
    loop {}
}

pub fn realloc(ptr: *mut u8, new_size: usize) -> *mut u8 {
    loop {}
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
