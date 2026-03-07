// exit: 0
// stdout: 42

fn main() {
    let mut x: i32 = 42;
    let p: *mut i32 = &mut x as *mut i32;
    let v = *p;
    println!("{}", v);
}
